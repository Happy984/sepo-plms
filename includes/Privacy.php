<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Hooks into Tools → Export / Erase Personal Data, and suggested privacy
 * policy text. Enrolment records hold names, emails, ID numbers and phone
 * numbers, so they must be exportable and erasable on request. Classroom
 * data (hand-ins, proctoring sessions, certificates, forum posts, chat,
 * attendance, checklists and notes) is exported and erased with it.
 */
final class Privacy
{
    public static function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
        add_action('admin_init', [self::class, 'policy']);
        add_action('deleted_user', [self::class, 'userDeleted']);
    }

    /** A deleted account takes its hand-in files, recordings and study data with it. */
    public static function userDeleted(int $userId): void
    {
        $messages = [];
        self::eraseClassroom($userId, $messages);
    }

    /** @param array<string,mixed> $list */
    public static function exporters(array $list): array
    {
        $list['sepo-lms'] = ['exporter_friendly_name' => __('Sepo LMS', 'sepo-lms'), 'callback' => [self::class, 'export']];
        return $list;
    }

    /** @param array<string,mixed> $list */
    public static function erasers(array $list): array
    {
        $list['sepo-lms'] = ['eraser_friendly_name' => __('Sepo LMS', 'sepo-lms'), 'callback' => [self::class, 'erase']];
        return $list;
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $data = [];
        if ($user) {
            foreach (Enrollments::forUser((int)$user->ID) as $e) {
                $fields = [];
                foreach (['reference', 'title', 'name', 'email', 'national_id', 'gender', 'location', 'dob', 'phone', 'status', 'method', 'currency', 'amount', 'created', 'paid_at'] as $k) {
                    $fields[] = ['name' => $k, 'value' => (string)($e[$k] ?? '')];
                }
                $data[] = ['group_id' => 'sepo-lms-enrollments', 'group_label' => __('Course enrolments', 'sepo-lms'), 'item_id' => 'enrollment-' . $e['id'], 'data' => $fields];
            }
            foreach (QuizResults::forUser((int)$user->ID) as $r) {
                $data[] = ['group_id' => 'sepo-lms-quizzes', 'group_label' => __('Quiz results', 'sepo-lms'), 'item_id' => 'quiz-' . $r['id'], 'data' => [
                    ['name' => __('Quiz', 'sepo-lms'), 'value' => get_the_title($r['quiz_id'])],
                    ['name' => __('Score', 'sepo-lms'), 'value' => $r['score'] . '%'],
                    ['name' => __('Attempts', 'sepo-lms'), 'value' => (string)$r['attempt']],
                    ['name' => __('Submitted', 'sepo-lms'), 'value' => (string)$r['submitted_at']],
                ]];
            }
            $data = array_merge($data, self::exportClassroom((int)$user->ID));
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $email);
        $removed = false;
        $retained = false;
        $messages = [];
        if ($user) {
            $uid = (int)$user->ID;
            // Paid enrolments are financial records; keep the amounts and
            // reference but strip the personal details.
            foreach (Enrollments::forUser($uid) as $e) {
                if ($e['status'] === 'paid') {
                    $wpdb->update(Enrollments::table(), ['name' => '', 'email' => '', 'national_id' => '', 'gender' => '', 'location' => '', 'dob' => '', 'phone' => ''], ['id' => $e['id']]); // phpcs:ignore WordPress.DB
                    $retained = true;
                } else {
                    Enrollments::delete((int)$e['id']);
                }
                $removed = true;
            }
            if ($retained) {
                $messages[] = __('Paid enrolments were kept as financial records with personal details removed.', 'sepo-lms');
            }
            $n = $wpdb->delete(QuizResults::table(), ['user_id' => $uid]); // phpcs:ignore WordPress.DB
            $removed = $removed || (bool)$n;
            Progress::deleteForUser($uid);
            $n = $wpdb->delete(Reviews::table(), ['user_id' => $uid]); // phpcs:ignore WordPress.DB
            $removed = $removed || (bool)$n;
            $removed = self::eraseClassroom($uid, $messages) || $removed;
        }
        $n = $wpdb->delete(QuizResults::table(), ['email' => $email, 'user_id' => 0]); // phpcs:ignore WordPress.DB
        $removed = $removed || (bool)$n;
        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true];
    }

    /** @return list<array<string,mixed>> */
    private static function exportClassroom(int $uid): array
    {
        global $wpdb;
        $data = [];
        $row = static function (string $group, string $label, string $id, array $fields) use (&$data): void {
            $out = [];
            foreach ($fields as $name => $value) {
                $out[] = ['name' => $name, 'value' => (string)$value];
            }
            $data[] = ['group_id' => $group, 'group_label' => $label, 'item_id' => $id, 'data' => $out];
        };

        foreach (Submissions::forUser($uid) as $sub) {
            $row('sepo-lms-submissions', __('Assignment hand-ins', 'sepo-lms'), 'submission-' . $sub['id'], [
                __('Assignment', 'sepo-lms') => get_the_title($sub['assignment_id']),
                __('Status', 'sepo-lms')     => Submissions::statusLabel((string)$sub['status']),
                __('Handed in', 'sepo-lms')  => (string)$sub['submitted_at'],
                __('Attempt', 'sepo-lms')    => (string)$sub['attempt'],
                __('Score', 'sepo-lms')      => $sub['score'] !== null ? Helpers::percent((float)$sub['score']) . '%' : '',
                __('Written answer', 'sepo-lms') => $sub['body'],
                __('Files', 'sepo-lms')      => implode(', ', array_map(static fn($f) => (string)($f['name'] ?? ''), $sub['files'])),
                __('Feedback', 'sepo-lms')   => $sub['feedback'],
            ]);
        }

        $sessions = $wpdb->get_results($wpdb->prepare('SELECT id, item_id, status, recording, violations, started, ended FROM ' . Proctor::table() . ' WHERE user_id = %d ORDER BY id ASC', $uid), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        foreach ($sessions as $ps) {
            $row('sepo-lms-proctoring', __('Proctoring sessions', 'sepo-lms'), 'proctor-' . $ps['id'], [
                __('Work', 'sepo-lms')         => get_the_title((int)$ps['item_id']),
                __('Status', 'sepo-lms')       => (string)$ps['status'],
                __('Screen recorded', 'sepo-lms') => $ps['recording'] ? __('Yes', 'sepo-lms') : __('No', 'sepo-lms'),
                __('Times left the page', 'sepo-lms') => (string)$ps['violations'],
                __('Started', 'sepo-lms')      => (string)$ps['started'],
                __('Ended', 'sepo-lms')        => (string)$ps['ended'],
            ]);
        }

        foreach (Certificates::forUser($uid) as $c) {
            $row('sepo-lms-certificates', __('Certificates', 'sepo-lms'), 'certificate-' . $c['id'], [
                __('Course', 'sepo-lms')  => (string)$c['course_title'],
                __('Name', 'sepo-lms')    => (string)$c['name'],
                __('Code', 'sepo-lms')    => (string)$c['code'],
                __('Issued', 'sepo-lms')  => (string)$c['issued'],
                __('Withdrawn', 'sepo-lms') => !empty($c['revoked']) ? __('Yes', 'sepo-lms') : __('No', 'sepo-lms'),
            ]);
        }

        foreach (Forums::byUser($uid) as $f) {
            $row('sepo-lms-forums', __('Forum posts', 'sepo-lms'), 'forum-' . $f['id'], [
                __('Course', 'sepo-lms')  => get_the_title((int)$f['course_id']),
                __('Title', 'sepo-lms')   => (string)($f['title'] ?? ''),
                __('Message', 'sepo-lms') => (string)$f['body'],
                __('Posted', 'sepo-lms')  => (string)$f['created'],
            ]);
        }

        foreach (Chat::byUser($uid) as $i => $m) {
            $row('sepo-lms-chat', __('Chat messages', 'sepo-lms'), 'chat-' . $i, [
                __('Course', 'sepo-lms')  => get_the_title((int)$m['course_id']),
                __('Message', 'sepo-lms') => (string)$m['body'],
                __('Sent', 'sepo-lms')    => (string)$m['created'],
            ]);
        }

        foreach (Attendance::byUser($uid) as $i => $a) {
            $row('sepo-lms-attendance', __('Attendance', 'sepo-lms'), 'attendance-' . $i, [
                __('Course', 'sepo-lms')  => get_the_title((int)$a['course_id']),
                __('Session', 'sepo-lms') => (string)$a['title'],
                __('Starts', 'sepo-lms')  => (string)$a['starts'],
                __('Status', 'sepo-lms')  => Attendance::statusLabels()[$a['status']] ?? (string)$a['status'],
            ]);
        }

        $todos = $wpdb->get_results($wpdb->prepare('SELECT id, course_id, text, done, due FROM ' . Study::todoTable() . ' WHERE user_id = %d ORDER BY id ASC', $uid), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        foreach ($todos as $t) {
            $row('sepo-lms-checklist', __('Study checklist', 'sepo-lms'), 'todo-' . $t['id'], [
                __('Course', 'sepo-lms') => (int)$t['course_id'] > 0 ? get_the_title((int)$t['course_id']) : '',
                __('Task', 'sepo-lms')   => (string)$t['text'],
                __('Done', 'sepo-lms')   => $t['done'] ? __('Yes', 'sepo-lms') : __('No', 'sepo-lms'),
                __('Due', 'sepo-lms')    => (string)$t['due'],
            ]);
        }

        foreach (Study::notes($uid) as $n) {
            $note = Study::note((int)$n['id'], $uid);
            $text = [];
            foreach ($note['blocks'] ?? [] as $b) {
                $line = trim(wp_strip_all_tags(str_replace('<br>', "\n", (string)($b['html'] ?? ''))));
                if ($line !== '') {
                    $text[] = $line;
                }
            }
            $row('sepo-lms-notes', __('Study notes', 'sepo-lms'), 'note-' . $n['id'], [
                __('Course', 'sepo-lms')  => $n['course_id'] > 0 ? get_the_title($n['course_id']) : '',
                __('Title', 'sepo-lms')   => $n['title'],
                __('Content', 'sepo-lms') => implode("\n", $text),
                __('Updated', 'sepo-lms') => $n['updated'],
            ]);
        }

        return $data;
    }

    /** @param list<string> $messages */
    private static function eraseClassroom(int $uid, array &$messages): bool
    {
        $n = Submissions::deleteForUser($uid)
            + Proctor::deleteForUser($uid)
            + Certificates::deleteForUser($uid)
            + Study::deleteForUser($uid)
            + Attendance::deleteForUser($uid)
            + Chat::deleteForUser($uid);
        $posts = Forums::anonymiseUser($uid);
        if ($posts > 0) {
            $messages[] = __('Forum posts were kept so discussions stay readable, with the author and text removed.', 'sepo-lms');
        }
        return ($n + $posts) > 0;
    }

    public static function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(__('Sepo LMS', 'sepo-lms'), wp_kses_post(
            '<p>' . __('When you enrol in a course we store the details you give on the enrolment form (name, email, and where asked national ID, gender, location, date of birth and phone number), the price you paid and the payment method. We also store your lesson progress, quiz answers and scores, and any course reviews you write.', 'sepo-lms') . '</p>'
            . '<p>' . __('In the classroom we store the work you hand in (written answers and files), grades and feedback, your forum posts and chat messages, your attendance, the certificates issued to you, and the checklist and notes you keep while studying.', 'sepo-lms') . '</p>'
            . '<p>' . __('Proctored work records when you leave the page or full screen. When screen recording is switched on for a piece of work, your entire screen is recorded while you work on it, after you agree; recordings are visible only to the course staff and are deleted after the retention period set by the site.', 'sepo-lms') . '</p>'
            . '<p>' . __('If you pay by card, PayPal or mobile money, the payment is handled by that provider (Stripe, PayPal or pawaPay), which receives the amount, a reference, your email address and, for mobile money, your phone number.', 'sepo-lms') . '</p>'
        ));
    }
}
