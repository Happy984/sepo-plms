<?php

declare(strict_types=1);
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables: names are built from $wpdb->prefix plus a constant suffix, values go through $wpdb->prepare().

namespace SepoLms;

defined('ABSPATH') || exit;

/**
 * Proof of payment for enrolments paid by bank transfer, mobile money or a
 * payment link. The learner attaches a screenshot, photo or PDF of the
 * payment from the course page; staff see it beside the enrolment and
 * accept it (which opens the course and emails the receipt and a note that
 * the proof was accepted) or decline it with a reason the learner reads.
 *
 * Files are kept in the plugin's private folder and are only served to the
 * learner who sent them and to course staff.
 */
final class Proofs
{
    public const TYPES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'];
    private const MAX = 8 * MB_IN_BYTES;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_payment_proofs';
    }

    public static function init(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route(Rest::NS, '/proofs', [
                'methods'             => 'POST',
                'permission_callback' => 'is_user_logged_in',
                'callback'            => [self::class, 'upload'],
            ]);
        });
        add_action('template_redirect', [self::class, 'serve'], 0);
        add_action('admin_post_slms_proof', [self::class, 'review']);
    }

    /** @return list<array<string,mixed>> */
    public static function forEnrollment(int $enrollmentId): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE enrollment_id = %d ORDER BY id DESC', $enrollmentId), ARRAY_A);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** Proofs waiting for a decision, by enrolment id. @return array<int,int> */
    public static function pendingCounts(): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results("SELECT enrollment_id, COUNT(*) AS n FROM " . self::table() . " WHERE status = 'pending' GROUP BY enrollment_id", ARRAY_A) as $r) {
            $out[(int) $r['enrollment_id']] = (int) $r['n'];
        }
        return $out;
    }

    public static function url(int $id, bool $view = true): string
    {
        return add_query_arg(['slms_proof' => $id, 'view' => $view ? 1 : 0, '_wpnonce' => wp_create_nonce('slms_proof_' . $id)], home_url('/'));
    }

    /** The learner sends a proof for their own pending enrolment. */
    public static function upload(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $uid = get_current_user_id();
        $enr = Enrollments::find(absint($r->get_param('enrollment')));
        if ($enr === null || (int) $enr['user_id'] !== $uid) {
            return new \WP_Error('sepo_lms', __('That enrolment was not found.', 'sepo-lms'), ['status' => 404]);
        }
        if ($enr['status'] === 'paid' || $enr['status'] === 'active') {
            return new \WP_Error('sepo_lms', __('This enrolment is already confirmed.', 'sepo-lms'), ['status' => 409]);
        }
        if (count(array_filter(self::forEnrollment((int) $enr['id']), static fn(array $p): bool => $p['status'] === 'pending')) >= 5) {
            return new \WP_Error('sepo_lms', __('Five proofs are already waiting to be checked. Please wait for a reply.', 'sepo-lms'), ['status' => 429]);
        }
        $file = $r->get_file_params()['proof'] ?? null;
        if (!is_array($file)) {
            return new \WP_Error('sepo_lms', __('Choose a photo, screenshot or PDF of your payment.', 'sepo-lms'), ['status' => 400]);
        }
        try {
            $stored = Storage::storeUpload($file, 'proofs/' . (int) $enr['id'], self::TYPES, self::MAX);
        } catch (\DomainException $e) {
            return new \WP_Error('sepo_lms', $e->getMessage(), ['status' => 400]);
        }
        global $wpdb;
        $wpdb->insert(self::table(), [
            'enrollment_id' => (int) $enr['id'],
            'user_id'       => $uid,
            'path'          => $stored['path'],
            'name'          => mb_substr($stored['name'], 0, 190),
            'mime'          => $stored['mime'],
            'size'          => (int) $stored['size'],
            'note'          => mb_substr(sanitize_text_field((string) $r->get_param('note')), 0, 255),
            'status'        => 'pending',
            'created'       => current_time('mysql', true),
        ]);
        if (Settings::get('email_admin')) {
            /* translators: 1: learner name, 2: course title */
            $subject = sprintf(__('Proof of payment: %1$s — %2$s', 'sepo-lms'), (string) $enr['name'], (string) $enr['title']);
            $link = admin_url('admin.php?page=slms-enrollments&s=' . rawurlencode((string) $enr['reference']));
            Mailer::send((string) Settings::get('admin_email'), $subject, '<p>' . esc_html__('A learner has sent proof of payment.', 'sepo-lms') . '</p><p><strong>' . esc_html((string) $enr['reference']) . '</strong> · ' . esc_html((string) $enr['title']) . '</p><p><a href="' . esc_url($link) . '">' . esc_html__('Check it and confirm the enrolment', 'sepo-lms') . '</a></p>');
        }
        /** Fires when a learner sends proof of payment. @param array<string,mixed> $enr */
        do_action('sepo_lms_proof_sent', $enr);
        return new \WP_REST_Response(['ok' => true, 'proofs' => self::publicList((int) $enr['id'])]);
    }

    /** @return list<array<string,mixed>> what the learner sees about their proofs */
    public static function publicList(int $enrollmentId): array
    {
        return array_map(static fn(array $p): array => [
            'id'      => (int) $p['id'],
            'name'    => (string) $p['name'],
            'status'  => (string) $p['status'],
            'reason'  => (string) $p['reason'],
            'created' => (string) $p['created'],
            'url'     => self::url((int) $p['id']),
        ], self::forEnrollment($enrollmentId));
    }

    public static function statusLabel(string $status): string
    {
        return [
            'pending'  => __('Waiting to be checked', 'sepo-lms'),
            'accepted' => __('Accepted', 'sepo-lms'),
            'declined' => __('Not accepted', 'sepo-lms'),
        ][$status] ?? $status;
    }

    /** Sends a proof file to the learner who sent it, or to course staff. */
    public static function serve(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below
        if (!isset($_GET['slms_proof'])) {
            return;
        }
        $id = absint($_GET['slms_proof']);
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        $view = !empty($_GET['view']);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url(add_query_arg([]))));
            exit;
        }
        $p = self::find($id);
        $enr = $p ? Enrollments::find((int) $p['enrollment_id']) : null;
        if (!$p || !$enr || !wp_verify_nonce($nonce, 'slms_proof_' . $id) || ((int) $p['user_id'] !== get_current_user_id() && !Access::canTeach((int) $enr['course_id']))) {
            wp_die(esc_html__('File not found.', 'sepo-lms'), '', ['response' => 404]);
        }
        $path = Storage::resolve((string) $p['path']);
        if ($path === '') {
            wp_die(esc_html__('File not found.', 'sepo-lms'), '', ['response' => 404]);
        }
        $inline = $view && (str_starts_with((string) $p['mime'], 'image/') || $p['mime'] === 'application/pdf');
        Storage::stream($path, (string) $p['mime'], (string) $p['name'], $inline, $p['mime'] === 'application/pdf' ? 'pdf' : 'media');
    }

    /** Staff accept or decline a proof. */
    public static function review(): void
    {
        $id = isset($_POST['proof']) ? absint($_POST['proof']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below
        check_admin_referer('slms_proof_' . $id);
        $p = self::find($id);
        $enr = $p ? Enrollments::find((int) $p['enrollment_id']) : null;
        if (!$p || !$enr || !Access::canTeach((int) $enr['course_id'])) {
            wp_die(esc_html__('You cannot review this proof.', 'sepo-lms'), '', ['response' => 403]);
        }
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash((string) $_POST['do'])) : '';
        $reason = isset($_POST['reason']) ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST['reason'])), 0, 255) : '';
        global $wpdb;
        $msg = '';
        if ($do === 'accept') {
            $wpdb->update(self::table(), ['status' => 'accepted', 'reviewer' => get_current_user_id(), 'reviewed' => current_time('mysql', true), 'reason' => ''], ['id' => $id]);
            // Settling the enrolment opens the course and sends the receipt.
            Enrollments::setStatus((int) $enr['id'], 'paid');
            self::tell($enr, true, '');
            $msg = 'proof_ok';
        } elseif ($do === 'decline') {
            $wpdb->update(self::table(), ['status' => 'declined', 'reviewer' => get_current_user_id(), 'reviewed' => current_time('mysql', true), 'reason' => $reason], ['id' => $id]);
            self::tell($enr, false, $reason);
            $msg = 'proof_no';
        }
        $back = wp_get_referer() ?: admin_url('admin.php?page=slms-enrollments');
        wp_safe_redirect(add_query_arg('slms_done', $msg, remove_query_arg('slms_done', $back)));
        exit;
    }

    /** Tells the learner by email what happened to their proof. @param array<string,mixed> $enr */
    private static function tell(array $enr, bool $ok, string $reason): void
    {
        $course = esc_url((string) get_permalink((int) $enr['course_id']));
        $hello = '<p>' . sprintf(/* translators: %s: learner name */ esc_html__('Hello %s,', 'sepo-lms'), esc_html((string) $enr['name'])) . '</p>';
        if ($ok) {
            /* translators: %s: course title */
            $subject = sprintf(__('Your proof of payment was accepted: %s', 'sepo-lms'), (string) $enr['title']);
            $body = $hello . '<p>' . esc_html__('Thank you. We checked your proof of payment and accepted it. The course is open now.', 'sepo-lms') . ' <a href="' . $course . '">' . esc_html__('Go to the course', 'sepo-lms') . '</a></p>';
        } else {
            /* translators: %s: course title */
            $subject = sprintf(__('About your proof of payment: %s', 'sepo-lms'), (string) $enr['title']);
            $body = $hello . '<p>' . esc_html__('We could not accept the proof of payment you sent.', 'sepo-lms') . '</p>'
                . ($reason !== '' ? '<p><strong>' . esc_html__('Reason:', 'sepo-lms') . '</strong> ' . esc_html($reason) . '</p>' : '')
                . '<p>' . esc_html__('You can send another one from the course page.', 'sepo-lms') . ' <a href="' . $course . '">' . esc_html__('Open the course', 'sepo-lms') . '</a></p>';
        }
        Mailer::send((string) $enr['email'], $subject, $body);
        /** Fires after a proof is accepted or declined. */
        do_action('sepo_lms_proof_reviewed', $enr, $ok, $reason);
    }

    /** The staff panel for one enrolment's proofs, for the enrolments screen. @param array<string,mixed> $enr */
    /** @var list<string> forms printed outside the enrolments table */
    private static array $forms = [];

    public static function printForms(): void
    {
        echo implode('', self::$forms); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in adminHtml().
        self::$forms = [];
    }

    public static function adminHtml(array $enr): string
    {
        $out = '';
        foreach (self::forEnrollment((int) $enr['id']) as $p) {
            $id = (int) $p['id'];
            $chip = '<span class="slms-chip slms-chip--' . esc_attr((string) $p['status']) . '">' . esc_html(self::statusLabel((string) $p['status'])) . '</span>';
            $out .= '<div class="slms-proof">' . $chip . ' <a href="' . esc_url(self::url($id)) . '" target="_blank" rel="noopener">' . esc_html((string) $p['name']) . '</a>'
                . ((string) $p['note'] !== '' ? '<br><span class="description">' . esc_html((string) $p['note']) . '</span>' : '');
            if ($p['status'] === 'pending' && $enr['status'] !== 'paid') {
                // The enrolments table sits inside the list's own form, so the
                // accept and decline forms are printed at the foot of the page
                // and the controls here point to them with the form attribute.
                $fa = 'slms-proof-a-' . $id;
                $fd = 'slms-proof-d-' . $id;
                foreach (['accept' => $fa, 'decline' => $fd] as $do => $fid) {
                    self::$forms[] = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="' . esc_attr($fid) . '" hidden>'
                        . '<input type="hidden" name="action" value="slms_proof"><input type="hidden" name="proof" value="' . $id . '"><input type="hidden" name="do" value="' . esc_attr($do) . '">'
                        . wp_nonce_field('slms_proof_' . $id, '_wpnonce', false, false) . '</form>';
                }
                $out .= '<div class="slms-proof__acts">'
                    . '<button type="submit" form="' . esc_attr($fa) . '" class="button button-small button-primary" data-busy>' . esc_html__('Accept and confirm', 'sepo-lms') . '</button>'
                    . '<span class="slms-proof__form"><label class="screen-reader-text" for="slms-proof-r-' . $id . '">' . esc_html__('Reason', 'sepo-lms') . '</label>'
                    . '<input type="text" form="' . esc_attr($fd) . '" id="slms-proof-r-' . $id . '" name="reason" placeholder="' . esc_attr__('Reason (the learner sees it)', 'sepo-lms') . '" maxlength="255"> '
                    . '<button type="submit" form="' . esc_attr($fd) . '" class="button button-small" data-busy>' . esc_html__('Decline', 'sepo-lms') . '</button></span></div>';
                if (!has_action('admin_footer', [self::class, 'printForms'])) {
                    add_action('admin_footer', [self::class, 'printForms']);
                }
            } elseif ($p['status'] === 'declined' && (string) $p['reason'] !== '') {
                $out .= '<br><span class="description">' . esc_html((string) $p['reason']) . '</span>';
            }
            $out .= '</div>';
        }
        return $out;
    }
}
