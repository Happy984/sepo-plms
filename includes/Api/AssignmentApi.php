<?php

declare(strict_types=1);

namespace SepoLms\Api;

use SepoLms\Access;
use SepoLms\Assignments;
use SepoLms\Downloads;
use SepoLms\Helpers;
use SepoLms\Mailer;
use SepoLms\PostTypes;
use SepoLms\Proctor;
use SepoLms\Progress;
use SepoLms\Settings;
use SepoLms\Storage;
use SepoLms\Submissions;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handing in upload assignments, and grading them.
 *
 *   POST /assignment/submit          multipart: assignment, body, confirm[], files[], proctor, auto
 *   POST /staff/submission/grade     id, points, feedback, return
 */
final class AssignmentApi extends Base
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        self::route('/assignment/submit', WP_REST_Server::CREATABLE, [self::class, 'submit']);
        self::route('/staff/submission/grade', WP_REST_Server::CREATABLE, [self::class, 'grade']);
    }

    public static function submit(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        if (!Settings::feature('assignments')) {
            return self::error(__('Assignments are switched off.', 'sepo-lms'), 403);
        }
        $post = get_post(absint($req->get_param('assignment')));
        if (!$post instanceof WP_Post || $post->post_type !== PostTypes::ASSIGNMENT || $post->post_status !== 'publish') {
            return self::error(__('Assignment not found.', 'sepo-lms'), 404);
        }
        $uid = get_current_user_id();
        $s = Assignments::settings($post);
        if ($s['type'] !== 'upload') {
            return self::error(__('This assignment is taken as a quiz.', 'sepo-lms'), 422);
        }
        $access = Access::item($post, $uid);
        if (!$access['allowed']) {
            return self::error($access['reason'], 403);
        }
        if ($s['course_id'] <= 0 || !\SepoLms\Enrollments::hasAccess($s['course_id'], $uid)) {
            if (!Access::canTeach($s['course_id'])) {
                return self::error(__('Enrol in the course to hand in work.', 'sepo-lms'), 403);
            }
        }

        $existing = Submissions::get($post->ID, $uid);
        $block = Assignments::submitBlock($s, $existing);
        if ($block !== '') {
            return self::error($block, 409);
        }

        $auto = rest_sanitize_boolean($req->get_param('auto'));
        $proctorId = Proctor::verifyForSubmit($uid, $post->ID, (string)$req->get_param('proctor'), $s['proctor']);

        $body = $s['text'] === 'off' ? '' : trim(sanitize_textarea_field((string)$req->get_param('body')));
        if (!$auto) {
            if ($s['text'] === 'required' && $body === '') {
                return self::error(__('Write your answer in the text box.', 'sepo-lms'), 422);
            }
            if ($body !== '' && $s['min_words'] > 0 && Assignments::wordCount($body) < $s['min_words']) {
                return self::error(sprintf(/* translators: %d: words */ __('Your written answer needs at least %d words.', 'sepo-lms'), $s['min_words']), 422);
            }
            if ($s['confirm'] && $s['requirements'] !== []) {
                $ticked = array_map('intval', (array)$req->get_param('confirm'));
                if (count(array_intersect(range(0, count($s['requirements']) - 1), $ticked)) < count($s['requirements'])) {
                    return self::error(__('Confirm each requirement before handing in.', 'sepo-lms'), 422);
                }
            }
        }

        $uploads = self::normaliseFiles($req->get_file_params()['files'] ?? null);
        if ($s['max_files'] === 0 && $uploads !== []) {
            return self::error(__('This assignment takes a written answer only.', 'sepo-lms'), 422);
        }
        if (count($uploads) > $s['max_files'] && $s['max_files'] > 0) {
            return self::error(sprintf(/* translators: %d: files */ _n('You can attach up to %d file.', 'You can attach up to %d files.', $s['max_files'], 'sepo-lms'), $s['max_files']), 422);
        }
        if (!$auto && $uploads === [] && $body === '') {
            return self::error($s['max_files'] > 0 && $s['text'] !== 'off'
                ? __('Attach a file or write an answer before handing in.', 'sepo-lms')
                : ($s['max_files'] > 0 ? __('Attach at least one file.', 'sepo-lms') : __('Write your answer in the text box.', 'sepo-lms')), 422);
        }

        $stored = [];
        try {
            foreach ($uploads as $file) {
                $stored[] = Storage::storeUpload($file, 'submissions/' . gmdate('Y/m'), $s['extensions'], $s['max_bytes']);
            }
        } catch (\DomainException $e) {
            foreach ($stored as $f) {
                Storage::delete($f['path']);
            }
            return self::error($e->getMessage(), 422);
        }

        $now = time();
        $late = Assignments::isPastDue($s, $now);
        $saved = Submissions::submit($post->ID, $s['course_id'], $uid, $body, $stored, $late, Assignments::daysLate($s, $now), $proctorId);
        if ($proctorId > 0) {
            Proctor::attach($proctorId, $auto);
        }
        if ($s['course_id'] > 0) {
            Progress::set($s['course_id'], $uid, $post->ID, true);
            do_action('sepo_lms_progress_changed', $s['course_id'], $uid);
        }
        self::notifyStaff($saved, $s);

        return self::ok(['submission' => self::present($saved, $s)]);
    }

    /**
     * $_FILES['files'] as a list of single-file arrays.
     *
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    private static function normaliseFiles($raw): array
    {
        if (!is_array($raw) || !isset($raw['name'])) {
            return [];
        }
        if (!is_array($raw['name'])) {
            return ($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$raw];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => $raw['name'][$i],
                'type'     => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error'    => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $raw['size'][$i] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * What the learner sees about their submission.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    public static function present(array $row, array $s): array
    {
        $files = [];
        foreach ($row['files'] as $i => $f) {
            $kind = Downloads::viewKind((string)$f['name']);
            $files[] = [
                'name' => (string)$f['name'],
                'size' => size_format((int)$f['size']),
                'url'  => Downloads::url('file', (int)$row['id'], (int)$i),
                'kind' => $kind,
                'view' => $kind !== '' ? Downloads::url('file', (int)$row['id'], (int)$i, true) : '',
            ];
        }
        return [
            'id'          => $row['id'],
            'status'      => $row['status'],
            'status_label'=> Submissions::statusLabel((string)$row['status']),
            'attempt'     => $row['attempt'],
            'late'        => $row['late'],
            'late_days'   => $row['late_days'],
            'submitted'   => Helpers::datetime((string)$row['submitted_at']),
            'files'       => $files,
            'body'        => $row['body'],
            'score'       => $row['score'],
            'points'      => $row['points'],
            'max_points'  => $s['points'],
            'feedback'    => $row['feedback'],
            'can_resubmit'=> Assignments::submitBlock($s, $row) === '',
        ];
    }

    /** Tell course staff that work came in. */
    private static function notifyStaff(array $row, array $s): void
    {
        $learner = get_userdata((int)$row['user_id']);
        $url = admin_url('admin.php?page=sepo-lms-submissions&id=' . (int)$row['id']);
        $subject = sprintf(/* translators: 1: learner, 2: assignment */ __('%1$s handed in %2$s', 'sepo-lms'), $learner ? $learner->display_name : '', $s['title']);
        $html = '<p>' . esc_html($subject) . ($row['late'] ? ' <strong>(' . esc_html__('late', 'sepo-lms') . ')</strong>' : '') . '</p>'
            . '<p><a href="' . esc_url($url) . '">' . esc_html__('Open the submission', 'sepo-lms') . '</a></p>';
        foreach (Access::staffUsers($s['course_id']) as $uid) {
            $u = get_userdata($uid);
            if ($u && $u->user_email !== '') {
                Mailer::send($u->user_email, $subject, $html);
            }
        }
    }

    public static function grade(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $row = Submissions::find(absint($req->get_param('id')));
        if ($row === null || !Access::canTeach((int)$row['course_id'])) {
            return self::error(__('Submission not found.', 'sepo-lms'), 404);
        }
        $return = rest_sanitize_boolean($req->get_param('return'));
        $pointsRaw = $req->get_param('points');
        if (!$return && ($pointsRaw === null || $pointsRaw === '' || !is_numeric($pointsRaw))) {
            return self::error(__('Enter the points awarded.', 'sepo-lms'), 422);
        }
        $feedback = sanitize_textarea_field((string)$req->get_param('feedback'));
        $saved = Submissions::grade((int)$row['id'], $return ? null : (float)$pointsRaw, $feedback, get_current_user_id(), $return);
        if ($saved === null) {
            return self::error(__('Submission not found.', 'sepo-lms'), 404);
        }
        $s = Assignments::settings((int)$saved['assignment_id']);
        self::notifyLearner($saved, $s, $return);
        return self::ok(['submission' => self::present($saved, $s)]);
    }

    private static function notifyLearner(array $row, array $s, bool $returned): void
    {
        $u = get_userdata((int)$row['user_id']);
        if (!$u || $u->user_email === '') {
            return;
        }
        $url = (string)get_permalink((int)$row['assignment_id']);
        $subject = $returned
            ? sprintf(/* translators: %s: assignment */ __('Please rework: %s', 'sepo-lms'), $s['title'])
            : sprintf(/* translators: %s: assignment */ __('Graded: %s', 'sepo-lms'), $s['title']);
        $html = '<h2 style="margin:0 0 10px">' . esc_html($s['title']) . '</h2>';
        if (!$returned) {
            $html .= '<p style="font-size:18px"><strong>' . esc_html(Helpers::percent((float)$row['score'])) . '%</strong> · '
                . esc_html(Helpers::percent((float)$row['points']) . ' / ' . Helpers::percent($s['points'])) . '</p>';
        } else {
            $html .= '<p>' . esc_html__('Your teacher returned your work so you can improve it and hand it in again.', 'sepo-lms') . '</p>';
        }
        if ($row['feedback'] !== '') {
            $html .= '<div style="background:#f3f4f6;border-radius:8px;padding:10px 14px;margin:12px 0">' . wpautop(esc_html($row['feedback'])) . '</div>';
        }
        $html .= '<p><a href="' . esc_url($url) . '">' . esc_html__('Open the assignment', 'sepo-lms') . '</a></p>';
        Mailer::send($u->user_email, $subject, $html);
    }
}
