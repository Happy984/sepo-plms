<?php

declare(strict_types=1);

namespace SepoLms;


/**
 * Completion certificates.
 *
 * A learner earns a course's certificate when they have access to the
 * course and meet its rules: every outline item complete (optional) and a
 * minimum final grade (optional; graded assignments must be marked first).
 * Certificates are issued automatically when the last requirement is met,
 * or on request from the course page, and each has a public verification
 * code.
 *
 *   ?slms_certificate={code}  the PDF (the learner or course staff)
 *   ?slms_verify={code}       public verification page
 */
final class Certificates
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'maybeRender'], 1);
        add_action('sepo_lms_quiz_submitted', static function (array $saved): void {
            if (!empty($saved['user_id']) && !empty($saved['course_id'])) {
                self::maybeAutoIssue((int)$saved['course_id'], (int)$saved['user_id']);
            }
        });
        add_action('sepo_lms_assignment_graded', static function (array $saved, bool $returned): void {
            if (!$returned && $saved['course_id'] > 0) {
                self::maybeAutoIssue((int)$saved['course_id'], (int)$saved['user_id']);
            }
        }, 10, 2);
        add_action('sepo_lms_progress_changed', [self::class, 'maybeAutoIssue'], 10, 2);
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_certificates';
    }

    /**
     * @return array{enabled:bool,require_all:bool,min_grade:float,title:string,body:string,signatory:string,signatory_title:string,show_grade:bool,design:string}
     */
    public static function courseSettings(int $courseId): array
    {
        $m = static fn(string $k): string => (string)get_post_meta($courseId, '_slms_cert_' . $k, true);
        return [
            'enabled'         => Settings::feature('certificates') && $m('enabled') !== '0',
            'require_all'     => $m('require_all') !== '0',
            'min_grade'       => min(100.0, max(0.0, (float)$m('min_grade'))),
            'title'           => $m('title') !== '' ? $m('title') : __('Certificate of Completion', 'sepo-lms'),
            'body'            => $m('body') !== '' ? $m('body') : __('has successfully completed the course', 'sepo-lms'),
            'signatory'       => $m('signatory') !== '' ? $m('signatory') : (string)Settings::get('certificate_signatory'),
            'signatory_title' => $m('signatory_title') !== '' ? $m('signatory_title') : (string)Settings::get('certificate_signatory_title'),
            'show_grade'      => $m('show_grade') === '1',
            'design'          => $m('design'),
        ];
    }

    /**
     * Where the learner stands.
     *
     * @return array{enabled:bool,eligible:bool,reasons:list<string>,done:int,total:int,grade:?float,pending:int,certificate:?array<string,mixed>}
     */
    public static function status(int $courseId, int $userId): array
    {
        $s = self::courseSettings($courseId);
        $cert = self::forCourseUser($courseId, $userId);
        $out = ['enabled' => $s['enabled'], 'eligible' => false, 'reasons' => [], 'done' => 0, 'total' => 0, 'grade' => null, 'pending' => 0, 'certificate' => $cert];
        if (!$s['enabled'] || $userId <= 0) {
            return $out;
        }
        if (!Enrollments::hasAccess($courseId, $userId)) {
            $out['reasons'][] = __('Enrol in the course first.', 'sepo-lms');
            return $out;
        }
        $outline = Access::outline($courseId, $userId);
        $out['total'] = count($outline);
        $out['done'] = count(array_filter($outline, static fn($r) => $r['done']));
        if ($s['require_all'] && $out['done'] < $out['total']) {
            $out['reasons'][] = sprintf(
                /* translators: 1: items done, 2: items in the course */
                __('Complete every item in the course (%1$d of %2$d done).', 'sepo-lms'),
                $out['done'],
                $out['total']
            );
        }
        $grade = Grades::course($courseId, $userId);
        $out['grade'] = $grade['grade'];
        $out['pending'] = $grade['pending'];
        if ($s['min_grade'] > 0) {
            if ($grade['pending'] > 0) {
                $out['reasons'][] = __('Wait for your submitted assignments to be graded.', 'sepo-lms');
            }
            if ($grade['grade'] === null || $grade['grade'] < $s['min_grade']) {
                $out['reasons'][] = sprintf(
                    /* translators: %s: minimum grade */
                    __('Reach a final grade of at least %s%%.', 'sepo-lms'),
                    Helpers::percent($s['min_grade'])
                );
            }
        }
        $out['eligible'] = $out['reasons'] === [];
        return $out;
    }

    /** Issue when eligible and not yet issued; silent otherwise. */
    public static function maybeAutoIssue(int $courseId, int $userId): void
    {
        if ($courseId <= 0 || $userId <= 0 || self::forCourseUser($courseId, $userId) !== null) {
            return;
        }
        if (self::status($courseId, $userId)['eligible']) {
            self::issue($courseId, $userId);
        }
    }

    /** @return array<string,mixed>|null the certificate, or null when the learner is not eligible */
    public static function issue(int $courseId, int $userId): ?array
    {
        $existing = self::forCourseUser($courseId, $userId);
        if ($existing !== null) {
            return $existing;
        }
        $status = self::status($courseId, $userId);
        if (!$status['eligible']) {
            return null;
        }
        return self::create($courseId, $userId, $status['grade']);
    }

    /** Issue without checking the course rules (staff action). @return array<string,mixed>|null */
    public static function issueManual(int $courseId, int $userId): ?array
    {
        return self::forCourseUser($courseId, $userId) ?? self::create($courseId, $userId, Grades::course($courseId, $userId)['grade']);
    }

    /** @return array<string,mixed>|null */
    private static function create(int $courseId, int $userId, ?float $grade): ?array
    {
        $user = get_userdata($userId);
        $enrol = Enrollments::forCourseUser($courseId, $userId);
        $name = trim((string)($enrol['name'] ?? '')) !== '' ? (string)$enrol['name'] : (string)($user->display_name ?? '');

        global $wpdb;
        for ($try = 0; $try < 5; $try++) {
            $ok = $wpdb->insert(self::table(), [ // phpcs:ignore WordPress.DB
                'code'         => self::newCode(),
                'course_id'    => $courseId,
                'user_id'      => $userId,
                'name'         => mb_substr($name, 0, 160),
                'course_title' => mb_substr(get_the_title($courseId), 0, 255),
                'grade'        => $grade,
                'issued'       => current_time('mysql', true),
                'revoked'      => 0,
            ]);
            if ($ok || self::forCourseUser($courseId, $userId) !== null) {
                break;
            }
        }
        $cert = self::forCourseUser($courseId, $userId);
        if ($cert !== null) {
            self::emailIssued($cert, $user instanceof \WP_User ? $user->user_email : '');

            /**
             * Fires after a certificate is issued.
             *
             * @param array<string,mixed> $cert
             */
            do_action('sepo_lms_certificate_issued', $cert);
        }
        return $cert;
    }

    private static function emailIssued(array $cert, string $email): void
    {
        if ($email === '') {
            return;
        }
        $subject = sprintf(/* translators: %s: course */ __('Your certificate for %s', 'sepo-lms'), $cert['course_title']);
        $body = '<h2 style="margin:0 0 8px">' . esc_html__('Congratulations!', 'sepo-lms') . '</h2>'
            . '<p>' . esc_html(sprintf(/* translators: %s: course */ __('You have completed %s and your certificate is ready.', 'sepo-lms'), $cert['course_title'])) . '</p>'
            . '<p><a href="' . esc_url(self::pdfUrl($cert['code'])) . '" style="display:inline-block;padding:10px 16px;background:' . esc_attr((string)Settings::get('accent')) . ';color:#fff;border-radius:8px;text-decoration:none">' . esc_html__('Download certificate', 'sepo-lms') . '</a></p>'
            . '<p style="color:#6b7280;font-size:13px">' . esc_html(sprintf(/* translators: %s: code */ __('Certificate code: %s', 'sepo-lms'), $cert['code'])) . '<br>'
            . esc_html__('Anyone can check it at:', 'sepo-lms') . ' <a href="' . esc_url(self::verifyUrl($cert['code'])) . '">' . esc_html(self::verifyUrl($cert['code'])) . '</a></p>';
        Mailer::send($email, $subject, $body);
    }

    /** @return array<string,mixed>|null */
    public static function forCourseUser(int $courseId, int $userId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE course_id = %d AND user_id = %d', $courseId, $userId), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $code) ?? '');
        if ($code === '') {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE code = %s', $code), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY issued DESC', $userId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * @param list<int> $courseIds
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $courseIds, string $search, int $perPage, int $page): array
    {
        if ($courseIds === []) {
            return ['rows' => [], 'total' => 0];
        }
        global $wpdb;
        $where = 'course_id IN (' . implode(',', array_map('intval', $courseIds)) . ')';
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (name LIKE %s OR code LIKE %s OR course_title LIKE %s)';
            $params = [$like, $like, $like];
        }
        $t = self::table();
        $total = (int)($params === [] ? $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$where}") : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$where}", $params))); // phpcs:ignore WordPress.DB
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE {$where} ORDER BY issued DESC LIMIT %d OFFSET %d", array_merge($params, [$perPage, max(0, ($page - 1) * $perPage)])), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        return ['rows' => array_map([self::class, 'hydrate'], $rows), 'total' => $total];
    }

    public static function setRevoked(int $id, bool $revoked): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['revoked' => $revoked ? 1 : 0], ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    public static function deleteForCourse(int $courseId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['course_id' => $courseId]); // phpcs:ignore WordPress.DB
    }

    public static function deleteForUser(int $userId): int
    {
        global $wpdb;
        return (int)$wpdb->delete(self::table(), ['user_id' => $userId]); // phpcs:ignore WordPress.DB
    }

    public static function pdfUrl(string $code): string
    {
        return add_query_arg(['slms_certificate' => $code], home_url('/'));
    }

    public static function verifyUrl(string $code): string
    {
        return add_query_arg(['slms_verify' => $code], home_url('/'));
    }

    private static function newCode(): string
    {
        $out = '';
        $alpha = self::ALPHABET;
        for ($i = 0; $i < 12; $i++) {
            $out .= $alpha[random_int(0, strlen($alpha) - 1)];
            if ($i === 3 || $i === 7) {
                $out .= '-';
            }
        }
        return $out;
    }

    // ------------------------------------------------------------- output

    public static function maybeRender(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- read-only views keyed by an unguessable code
        if (isset($_GET['slms_verify'])) {
            self::renderVerify(sanitize_text_field(wp_unslash((string)$_GET['slms_verify'])));
        }
        if (!isset($_GET['slms_certificate'])) {
            return;
        }
        $code = sanitize_text_field(wp_unslash((string)$_GET['slms_certificate']));
        // phpcs:enable
        $cert = self::findByCode($code);
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::pdfUrl($code)));
            exit;
        }
        if ($cert === null || ($cert['user_id'] !== get_current_user_id() && !Access::canTeach($cert['course_id']))) {
            wp_die(esc_html__('Certificate not found.', 'sepo-lms'), '', ['response' => 404]);
        }
        if ($cert['revoked']) {
            wp_die(esc_html__('This certificate has been withdrawn.', 'sepo-lms'), '', ['response' => 410]);
        }
        $pdf = self::pdf($cert);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certificate-' . sanitize_file_name($cert['code']) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** Build the certificate PDF from the course's design. */
    public static function pdf(array $cert): string
    {
        $design = CertificateDesigns::forCourse((int)$cert['course_id']);
        $data = CertificateDesigns::certData($cert);
        return CertificateDesigns::render($design, $data, $data['title'] . ' - ' . $cert['name']);
    }

    private static function renderVerify(string $code): void
    {
        $cert = self::findByCode($code);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        $valid = $cert !== null && !$cert['revoked'];
        $accent = esc_attr((string)Settings::get('accent'));
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html__('Certificate verification', 'sepo-lms') . '</title>'
            . '<style>body{margin:0;background:#f3f4f6;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2937}'
            . '.c{max-width:560px;margin:40px auto;padding:0 16px}.k{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;overflow-wrap:anywhere}'
            . '.s{display:inline-block;padding:4px 12px;border-radius:999px;font-weight:700;font-size:13px}.ok{background:#dcfce7;color:#166534}.no{background:#fee2e2;color:#991b1b}'
            . 'dl{margin:18px 0 0;display:grid;grid-template-columns:auto 1fr;gap:8px 16px}dt{color:#6b7280}dd{margin:0;font-weight:600}h1{font-size:20px;margin:12px 0 0}'
            . 'form{margin-top:18px;display:flex;gap:8px}input{flex:1;padding:10px;border:1px solid #d1d5db;border-radius:8px;font:inherit}button{padding:10px 14px;border:0;border-radius:8px;background:' . $accent . ';color:#fff;font:inherit;font-weight:600}</style></head><body><div class="c"><div class="k">';
        echo '<div class="s ' . ($valid ? 'ok' : 'no') . '">' . esc_html($valid ? __('Valid certificate', 'sepo-lms') : ($cert !== null ? __('Withdrawn', 'sepo-lms') : __('Not found', 'sepo-lms'))) . '</div>';
        if ($cert !== null) {
            echo '<h1>' . esc_html($cert['course_title']) . '</h1><dl>'
                . '<dt>' . esc_html__('Awarded to', 'sepo-lms') . '</dt><dd>' . esc_html($cert['name']) . '</dd>'
                . '<dt>' . esc_html__('Issued', 'sepo-lms') . '</dt><dd>' . esc_html(Helpers::datetime((string)$cert['raw_issued'])) . '</dd>'
                . '<dt>' . esc_html__('Issued by', 'sepo-lms') . '</dt><dd>' . esc_html((string)Settings::get('business_name')) . '</dd>'
                . '<dt>' . esc_html__('Code', 'sepo-lms') . '</dt><dd>' . esc_html($cert['code']) . '</dd></dl>';
        } else {
            echo '<p>' . esc_html__('No certificate matches that code. Check it and try again.', 'sepo-lms') . '</p>';
        }
        echo '<form method="get" action="' . esc_url(home_url('/')) . '"><label class="screen-reader-text" for="v">' . esc_html__('Certificate code', 'sepo-lms') . '</label>'
            . '<input id="v" name="slms_verify" placeholder="XXXX-XXXX-XXXX" value="' . esc_attr($code) . '"><button>' . esc_html__('Check', 'sepo-lms') . '</button></form>';
        echo '</div></div></body></html>';
        exit;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function hydrate(array $r): array
    {
        $r['id'] = (int)$r['id'];
        $r['course_id'] = (int)$r['course_id'];
        $r['user_id'] = (int)$r['user_id'];
        $r['grade'] = $r['grade'] !== null ? (float)$r['grade'] : null;
        $r['revoked'] = !empty($r['revoked']);
        $r['raw_issued'] = (string)$r['issued'];
        return $r;
    }
}
