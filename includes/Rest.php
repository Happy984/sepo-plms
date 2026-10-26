<?php

declare(strict_types=1);

namespace SepoLms;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API under /wp-json/sepo-lms/v1.
 *
 * Signed-in routes rely on WordPress cookie authentication, so the browser
 * sends the wp_rest nonce in X-WP-Nonce.
 */
final class Rest
{
    public const NS = 'sepo-lms/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $signedIn = static fn(): bool => is_user_logged_in();
        $admin = static fn(): bool => current_user_can(Activator::CAP);
        $public = '__return_true';

        $r = static function (string $path, string $methods, callable $cb, callable|string $perm) {
            register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => $cb, 'permission_callback' => $perm]);
        };

        $r('/enroll', WP_REST_Server::CREATABLE, [self::class, 'enroll'], $signedIn);
        $r('/enrollment', WP_REST_Server::READABLE, [self::class, 'enrollment'], $signedIn);
        $r('/progress', WP_REST_Server::CREATABLE, [self::class, 'progress'], $signedIn);
        $r('/reviews', WP_REST_Server::CREATABLE, [self::class, 'review'], $signedIn);
        $r('/reviews/delete', WP_REST_Server::CREATABLE, [self::class, 'reviewDelete'], $signedIn);
        $r('/quiz/submit', WP_REST_Server::CREATABLE, [self::class, 'quizSubmit'], $public);
        $r('/quiz/history', WP_REST_Server::READABLE, [self::class, 'quizHistory'], $signedIn);

        $r('/admin/enrollment', WP_REST_Server::CREATABLE, [self::class, 'adminEnrollment'], $admin);
        $r('/admin/quiz-result/delete', WP_REST_Server::CREATABLE, [self::class, 'adminQuizResultDelete'], $admin);
        $r('/admin/quiz-stats', WP_REST_Server::READABLE, [self::class, 'adminQuizStats'], $admin);

        $r('/pay/return', WP_REST_Server::READABLE, [Payments::class, 'handleReturn'], $public);
        $r('/webhook/stripe', WP_REST_Server::CREATABLE, [Payments::class, 'stripeWebhook'], $public);
        $r('/webhook/pawapay', WP_REST_Server::CREATABLE, [Payments::class, 'pawapayWebhook'], $public);
        $r('/webhook/paypal', WP_REST_Server::CREATABLE, [Payments::class, 'paypalWebhook'], $public);
    }

    private static function error(string $message, int $status): WP_Error
    {
        return new WP_Error('sepo_lms', $message, ['status' => $status]);
    }

    private static function course(WP_REST_Request $req): ?array
    {
        $id = absint($req->get_param('course'));
        return $id > 0 && get_post_status($id) === 'publish' ? Course::get($id) : null;
    }

    // ------------------------------------------------------------- enrolment

    public static function enroll(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        try {
            $out = Payments::enroll(absint($req->get_param('course')), get_current_user_id(), [
                'name'        => $req->get_param('name'),
                'email'       => $req->get_param('email'),
                'national_id' => $req->get_param('national_id'),
                'gender'      => $req->get_param('gender'),
                'location'    => $req->get_param('location'),
                'dob'         => $req->get_param('dob'),
                'method'      => $req->get_param('method'),
                'phone'       => $req->get_param('phone'),
            ]);
        } catch (\DomainException $e) {
            return self::error($e->getMessage(), $e->getCode() ?: 400);
        }
        return new WP_REST_Response($out, 200);
    }

    /** Status of one enrolment; asks the gateway when still pending. Used for polling. */
    public static function enrollment(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $row = Enrollments::findByReference(sanitize_text_field((string)$req->get_param('ref')));
        if ($row === null || ((int)$row['user_id'] !== get_current_user_id() && !current_user_can(Activator::CAP))) {
            return self::error(__('Enrolment not found.', 'sepo-lms'), 404);
        }
        $state = $row['status'] === 'pending' ? Payments::verify($row) : $row['status'];
        $row = Enrollments::find((int)$row['id']) ?? $row;
        return new WP_REST_Response([
            'reference'      => $row['reference'],
            'status'         => $row['status'],
            'state'          => $state,
            'gateway_status' => $row['gateway_status'],
            'paid'           => $row['status'] === 'paid',
        ], 200);
    }

    // -------------------------------------------------------------- progress

    public static function progress(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $uid = get_current_user_id();
        $item = get_post(absint($req->get_param('item')));
        if (!$item instanceof WP_Post || $item->post_type !== PostTypes::LESSON || $item->post_status !== 'publish') {
            return self::error(__('Lesson not found.', 'sepo-lms'), 404);
        }
        $courseId = PostTypes::courseOf($item->ID);
        if ($courseId <= 0) {
            return self::error(__('This lesson is not part of a course.', 'sepo-lms'), 422);
        }
        $access = Access::item($item, $uid);
        if (!$access['allowed']) {
            return self::error($access['reason'], 403);
        }
        $done = rest_sanitize_boolean($req->get_param('done'));
        Progress::set($courseId, $uid, $item->ID, $done);
        do_action('sepo_lms_progress_changed', $courseId, $uid);

        $outline = Access::outline($courseId, $uid);
        $doneCount = count(array_filter($outline, static fn($r) => $r['done']));
        return new WP_REST_Response(['done' => $done, 'completed' => $doneCount, 'total' => count($outline)], 200);
    }

    // --------------------------------------------------------------- reviews

    public static function review(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $course = self::course($req);
        if ($course === null) {
            return self::error(__('Course not found.', 'sepo-lms'), 404);
        }
        $uid = get_current_user_id();
        if (Enrollments::status($course['id'], $uid) === null && !Access::canEditCourse($course['id'])) {
            return self::error(__('Only enrolled learners can review this course.', 'sepo-lms'), 403);
        }
        $user = wp_get_current_user();
        try {
            Reviews::save($course['id'], $uid, $user->display_name, (int)$req->get_param('rating'), (string)$req->get_param('body'));
        } catch (\InvalidArgumentException $e) {
            return self::error($e->getMessage(), 422);
        }
        return new WP_REST_Response(['saved' => true, 'summary' => Reviews::summary($course['id'])], 200);
    }

    public static function reviewDelete(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $review = Reviews::find(absint($req->get_param('id')));
        if ($review === null) {
            return self::error(__('Review not found.', 'sepo-lms'), 404);
        }
        if ((int)$review['user_id'] !== get_current_user_id() && !Access::canEditCourse((int)$review['course_id'])) {
            return self::error(__('You can only delete your own reviews.', 'sepo-lms'), 403);
        }
        Reviews::delete((int)$review['id']);
        return new WP_REST_Response(['deleted' => true], 200);
    }

    // --------------------------------------------------------------- quizzes

    public static function quizSubmit(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $quiz = get_post(absint($req->get_param('quiz')));
        if (!$quiz instanceof WP_Post || $quiz->post_type !== PostTypes::QUIZ || $quiz->post_status !== 'publish') {
            return self::error(__('Quiz not found.', 'sepo-lms'), 404);
        }
        $s = Quiz::settings($quiz);
        $uid = get_current_user_id();

        if ($uid <= 0 && ($s['require_login'] || $s['require_enrollment'])) {
            return self::error(__('You must be signed in to submit this quiz.', 'sepo-lms'), 401);
        }
        if ($uid > 0 && !current_user_can('read')) {
            return self::error(__('You cannot take this quiz.', 'sepo-lms'), 403);
        }
        // Guests need the REST nonce too, so a form on another site cannot post here.
        if ($uid <= 0 && !wp_verify_nonce((string)$req->get_header('x_wp_nonce'), 'wp_rest')) {
            return self::error(__('Your session expired. Reload the page and try again.', 'sepo-lms'), 403);
        }

        if ($s['require_enrollment'] && $s['course_id'] > 0) {
            $access = Access::item($quiz, $uid);
            if (!$access['allowed']) {
                return self::error($access['reason'], 403);
            }
        }

        // A quiz used by an assignment is taken from the assignment page,
        // where the assignment's due date and proctoring apply.
        $assignment = null;
        $linkedTo = Assignments::forQuiz($quiz->ID);
        if ($linkedTo > 0) {
            if (absint($req->get_param('assignment')) !== $linkedTo) {
                return self::error(__('Take this quiz from its assignment page.', 'sepo-lms'), 403);
            }
            $assignmentPost = get_post($linkedTo);
            if ($uid <= 0 || !$assignmentPost instanceof WP_Post) {
                return self::error(__('You must be signed in to submit this quiz.', 'sepo-lms'), 401);
            }
            $aAccess = Access::item($assignmentPost, $uid);
            if (!$aAccess['allowed']) {
                return self::error($aAccess['reason'], 403);
            }
            $assignment = Assignments::settings($assignmentPost);
            // Retakes follow the quiz's own attempt rules; only a closed due date blocks here.
            if ($assignment['late'] === 'deny' && Assignments::isPastDue($assignment)) {
                return self::error(__('The due date has passed and late work is not accepted.', 'sepo-lms'), 409);
            }
        }
        $proctorItem = $assignment !== null ? $assignment['id'] : $quiz->ID;
        $proctorRules = $assignment !== null ? $assignment['proctor'] : Proctor::settingsFor($quiz->ID);
        $auto = rest_sanitize_boolean($req->get_param('auto'));
        try {
            $proctorId = $uid > 0 ? Proctor::verifyForSubmit($uid, $proctorItem, (string)$req->get_param('proctor'), $proctorRules) : 0;
        } catch (\DomainException $e) {
            return self::error($e->getMessage(), (int)$e->getCode() ?: 403);
        }
        if ($uid <= 0 && Proctor::required($proctorRules)) {
            return self::error(__('You must be signed in to submit this quiz.', 'sepo-lms'), 401);
        }

        $email = '';
        $guest = '';
        if ($uid <= 0) {
            $email = sanitize_email((string)$req->get_param('email'));
            if ($s['collect_email'] && !is_email($email)) {
                return self::error(__('Enter a valid email address.', 'sepo-lms'), 422);
            }
            $guest = $email !== '' ? 'e:' . md5(strtolower($email)) : 'g:' . wp_generate_uuid4();
        } else {
            $email = wp_get_current_user()->user_email;
        }

        $prev = QuizResults::get($quiz->ID, $uid, $guest);
        if ($prev !== null && !$s['allow_retake']) {
            return self::error(__('You have already taken this quiz.', 'sepo-lms'), 403);
        }
        if ($s['max_attempts'] > 0 && $prev !== null && $prev['attempt'] >= $s['max_attempts']) {
            return self::error(__('You have used all your attempts for this quiz.', 'sepo-lms'), 403);
        }

        if ($uid > 0 && $s['time_limit'] > 0) {
            $started = (int)get_user_meta($uid, '_slms_qstart_' . $quiz->ID, true);
            if ($started > 0 && time() - $started > $s['time_limit'] * 60 + 120) {
                delete_user_meta($uid, '_slms_qstart_' . $quiz->ID);
                return self::error(__('Time is up for this attempt. Reload the quiz to start again.', 'sepo-lms'), 403);
            }
        }

        $questions = Quiz::questions($quiz->ID);
        if ($questions === []) {
            return self::error(__('This quiz has no questions yet.', 'sepo-lms'), 422);
        }
        $answers = $req->get_param('answers');
        $answers = is_array($answers) ? $answers : [];

        $result = Quiz::score($questions, $answers, $s['passing_score']);
        $result['weight'] = $s['weight'];

        // Keep only answers to real questions.
        $kept = [];
        foreach ($questions as $q) {
            if (isset($result['results'][$q['id']]['user_answer'])) {
                $kept[$q['id']] = $result['results'][$q['id']]['user_answer'];
            }
        }
        $saved = QuizResults::save($quiz->ID, $s['course_id'], $uid, $guest, $email, $result, $kept);

        if ($uid > 0) {
            delete_user_meta($uid, '_slms_qstart_' . $quiz->ID);
            if ($result['passed'] && $s['course_id'] > 0) {
                Progress::set($s['course_id'], $uid, $quiz->ID, true);
            }
        }
        if ($assignment !== null) {
            $now = time();
            $late = Assignments::isPastDue($assignment, $now);
            $days = Assignments::daysLate($assignment, $now);
            Submissions::recordQuiz(
                $assignment['id'],
                $assignment['course_id'],
                $uid,
                (float)$result['score'],
                Assignments::applyPenalty((float)$result['score'], $assignment, $days),
                $late,
                $days,
                $proctorId,
                $assignment['points']
            );
            if ($assignment['course_id'] > 0) {
                Progress::set($assignment['course_id'], $uid, $assignment['id'], true);
            }
        }
        if ($proctorId > 0) {
            Proctor::attach($proctorId, $auto);
        }
        if ($uid > 0) {
            $progressCourse = $assignment !== null ? $assignment['course_id'] : $s['course_id'];
            if ($progressCourse > 0) {
                do_action('sepo_lms_progress_changed', $progressCourse, $uid);
            }
        }

        /**
         * Fires after a quiz is submitted and saved.
         *
         * @param array<string,mixed> $saved The stored result.
         * @param array<string,mixed> $settings The quiz settings.
         */
        do_action('sepo_lms_quiz_submitted', $saved, $s);

        $data = [
            'saved'        => true,
            'attempt'      => $saved['attempt'] ?? 1,
            'max_attempts' => $s['max_attempts'],
            'can_retake'   => $s['allow_retake'] && ($s['max_attempts'] === 0 || ($saved['attempt'] ?? 1) < $s['max_attempts']),
            'show_results' => $s['show_results'],
        ];
        if ($s['show_results']) {
            $data += [
                'total'      => $result['total'],
                'answered'   => $result['answered'],
                'correct'    => $result['correct'],
                'score'      => $result['score'],
                'passed'     => $result['passed'],
                'best_score' => $saved['best_score'] ?? $result['score'],
                'points'     => Quiz::formatPoints((float)$result['points_earned']) . ' / ' . Quiz::formatPoints((float)$result['points_total']),
            ];
            if ($s['review_answers']) {
                // Correct answers stay hidden while another attempt is possible.
                $data['review'] = Quiz::review($quiz->ID, $saved, !$data['can_retake']);
            }
        }
        return new WP_REST_Response($data, 200);
    }

    public static function quizHistory(WP_REST_Request $req): WP_REST_Response
    {
        $uid = get_current_user_id();
        $rows = [];
        foreach (QuizResults::forUser($uid) as $r) {
            $rows[] = [
                'quiz'      => $r['quiz_id'],
                'title'     => get_the_title($r['quiz_id']),
                'score'     => $r['score'],
                'best'      => $r['best_score'],
                'passed'    => $r['passed'],
                'attempt'   => $r['attempt'],
                'submitted' => $r['submitted_at'],
            ];
        }
        return new WP_REST_Response(['results' => $rows, 'stats' => QuizResults::userStats($uid)], 200);
    }

    // ----------------------------------------------------------------- admin

    public static function adminEnrollment(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = absint($req->get_param('id'));
        $action = sanitize_key((string)$req->get_param('action'));
        if (Enrollments::find($id) === null) {
            return self::error(__('Enrolment not found.', 'sepo-lms'), 404);
        }
        $ok = match ($action) {
            'activate' => Enrollments::setStatus($id, 'active'),
            'pending'  => Enrollments::setStatus($id, 'pending'),
            'paid'     => Enrollments::setStatus($id, 'paid'),
            'remove'   => Enrollments::delete($id),
            default    => null,
        };
        if ($ok === null) {
            return self::error(__('Unknown action.', 'sepo-lms'), 422);
        }
        return new WP_REST_Response(['ok' => (bool)$ok, 'enrollment' => Enrollments::find($id)], $ok ? 200 : 409);
    }

    public static function adminQuizResultDelete(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $ok = QuizResults::delete(absint($req->get_param('id')));
        return $ok ? new WP_REST_Response(['deleted' => true], 200) : self::error(__('Result not found.', 'sepo-lms'), 404);
    }

    public static function adminQuizStats(WP_REST_Request $req): WP_REST_Response
    {
        return new WP_REST_Response(QuizResults::quizStats(absint($req->get_param('quiz'))), 200);
    }
}
