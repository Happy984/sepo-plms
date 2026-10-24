<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Hooks into Tools → Export / Erase Personal Data, and suggested privacy
 * policy text. Enrolment records hold names, emails, ID numbers and phone
 * numbers, so they must be exportable and erasable on request.
 */
final class Privacy
{
    public static function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
        add_action('admin_init', [self::class, 'policy']);
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
        }
        $n = $wpdb->delete(QuizResults::table(), ['email' => $email, 'user_id' => 0]); // phpcs:ignore WordPress.DB
        $removed = $removed || (bool)$n;
        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true];
    }

    public static function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(__('Sepo LMS', 'sepo-lms'), wp_kses_post(
            '<p>' . __('When you enrol in a course we store the details you give on the enrolment form (name, email, and where asked national ID, gender, location, date of birth and phone number), the price you paid and the payment method. We also store your lesson progress, quiz answers and scores, and any course reviews you write.', 'sepo-lms') . '</p>'
            . '<p>' . __('If you pay by card, PayPal or mobile money, the payment is handled by that provider (Stripe, PayPal or pawaPay), which receives the amount, a reference, your email address and, for mobile money, your phone number.', 'sepo-lms') . '</p>'
        ));
    }
}
