<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Access;
use SepoLms\Certificates;
use SepoLms\Enrollments;
use SepoLms\Helpers;
use SepoLms\Settings;

/**
 * LMS → Certificates: issued certificates, with withdraw, restore, delete
 * and manual issue.
 */
final class CertificatesPage
{
    public const SLUG = 'sepo-lms-certificates';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 25);
        add_action('admin_post_slms_certificate', [self::class, 'handle']);
    }

    public static function menu(): void
    {
        if (!Settings::feature('certificates')) {
            return;
        }
        add_submenu_page(Menu::SLUG, __('Certificates', 'sepo-lms'), __('Certificates', 'sepo-lms'), 'edit_posts', self::SLUG, [self::class, 'render']);
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    public static function render(): void
    {
        $courses = Access::teachableCourses(get_current_user_id());
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $course = isset($_GET['course']) ? absint($_GET['course']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string)$_GET['s'])) : '';
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $msg = isset($_GET['msg']) ? sanitize_key(wp_unslash((string)$_GET['msg'])) : '';
        // phpcs:enable
        $scope = $course > 0 && in_array($course, $courses, true) ? [$course] : $courses;
        $res = Certificates::query($scope, $search, 25, $page);
        $notices = [
            'issued'   => __('Certificate issued.', 'sepo-lms'),
            'revoked'  => __('Certificate withdrawn. Its verification page now says so.', 'sepo-lms'),
            'restored' => __('Certificate restored.', 'sepo-lms'),
            'deleted'  => __('Certificate deleted. The learner can earn it again.', 'sepo-lms'),
            'exists'   => __('That learner already has this certificate.', 'sepo-lms'),
        ];
        ?>
        <div class="wrap slms-admin">
            <h1><?php esc_html_e('Certificates', 'sepo-lms'); ?></h1>
            <?php if (isset($notices[$msg])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notices[$msg]); ?></p></div>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('Certificates are issued automatically when a learner meets the course’s certificate rules (set on the course edit screen). Learners download them from the course page and My courses.', 'sepo-lms'); ?></p>

            <form method="get" class="slms-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <?php SubmissionsPage::courseSelect($courses, $course); ?>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Name, code or course', 'sepo-lms'); ?>">
                <button class="button"><?php esc_html_e('Filter', 'sepo-lms'); ?></button>
            </form>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Code', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Learner', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Course', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Grade', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Issued', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Actions', 'sepo-lms'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ($res['rows'] === []): ?>
                    <tr><td colspan="6"><?php esc_html_e('No certificates issued yet.', 'sepo-lms'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($res['rows'] as $c): ?>
                    <tr>
                        <td><code><?php echo esc_html($c['code']); ?></code><?php echo $c['revoked'] ? ' <span class="slms-st slms-st--failed">' . esc_html__('Withdrawn', 'sepo-lms') . '</span>' : ''; ?></td>
                        <td><?php echo esc_html($c['name']); ?></td>
                        <td><?php echo esc_html($c['course_title']); ?></td>
                        <td><?php echo $c['grade'] !== null ? esc_html(Helpers::percent((float)$c['grade']) . '%') : '—'; ?></td>
                        <td><?php echo esc_html(Helpers::datetime((string)$c['raw_issued'])); ?></td>
                        <td class="slms-actions-cell">
                            <?php if (!$c['revoked']): ?><a href="<?php echo esc_url(Certificates::pdfUrl($c['code'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('PDF', 'sepo-lms'); ?></a> · <?php endif; ?>
                            <a href="<?php echo esc_url(Certificates::verifyUrl($c['code'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Verify page', 'sepo-lms'); ?></a> ·
                            <?php self::actionLink($c['id'], $c['revoked'] ? 'restore' : 'revoke', $c['revoked'] ? __('Restore', 'sepo-lms') : __('Withdraw', 'sepo-lms')); ?> ·
                            <?php self::actionLink($c['id'], 'delete', __('Delete', 'sepo-lms'), true); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php SubmissionsPage::pager($res['total'], 25, $page); ?>

            <?php if ($course > 0 && in_array($course, $courses, true)): ?>
                <?php $learners = Enrollments::learners($course); ?>
                <h2><?php esc_html_e('Issue a certificate by hand', 'sepo-lms'); ?></h2>
                <p class="description"><?php esc_html_e('Skips the course’s certificate rules. Use it for learners who completed the course another way.', 'sepo-lms'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="slms-filters">
                    <input type="hidden" name="action" value="slms_certificate">
                    <input type="hidden" name="do" value="issue">
                    <input type="hidden" name="course" value="<?php echo (int)$course; ?>">
                    <?php wp_nonce_field('slms_certificate_issue_' . $course); ?>
                    <label class="screen-reader-text" for="slms-cert-user"><?php esc_html_e('Learner', 'sepo-lms'); ?></label>
                    <select name="user" id="slms-cert-user" required>
                        <option value=""><?php esc_html_e('— Choose a learner —', 'sepo-lms'); ?></option>
                        <?php foreach ($learners as $l): ?>
                            <option value="<?php echo (int)$l['user_id']; ?>"><?php echo esc_html($l['name'] . ' <' . $l['email'] . '>'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary"><?php esc_html_e('Issue certificate', 'sepo-lms'); ?></button>
                </form>
            <?php elseif ($courses !== []): ?>
                <p class="description"><?php esc_html_e('Choose a course above to issue a certificate by hand.', 'sepo-lms'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function actionLink(int $id, string $do, string $label, bool $danger = false): void
    {
        $url = wp_nonce_url(add_query_arg(['action' => 'slms_certificate', 'do' => $do, 'id' => $id], admin_url('admin-post.php')), 'slms_certificate_' . $do . '_' . $id);
        $confirm = $danger ? ' onclick="return confirm(' . esc_attr((string)wp_json_encode(__('Delete this certificate? Its code will stop verifying.', 'sepo-lms'))) . ')"' : '';
        echo '<a href="' . esc_url($url) . '"' . ($danger ? ' class="slms-danger-link"' : '') . $confirm . '>' . esc_html($label) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function handle(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- verified per action below
        $do = isset($_REQUEST['do']) ? sanitize_key(wp_unslash((string)$_REQUEST['do'])) : '';
        if ($do === 'issue') {
            $course = isset($_POST['course']) ? absint($_POST['course']) : 0;
            $user = isset($_POST['user']) ? absint($_POST['user']) : 0;
            check_admin_referer('slms_certificate_issue_' . $course);
            if (!Access::canTeach($course) || $user <= 0) {
                wp_die(esc_html__('You cannot issue certificates for this course.', 'sepo-lms'));
            }
            $msg = Certificates::forCourseUser($course, $user) !== null ? 'exists' : 'issued';
            if ($msg === 'issued') {
                Certificates::issueManual($course, $user);
            }
            wp_safe_redirect(self::url(['course' => $course, 'msg' => $msg]));
            exit;
        }
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        // phpcs:enable
        check_admin_referer('slms_certificate_' . $do . '_' . $id);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT course_id FROM ' . Certificates::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        if (!is_array($row) || !Access::canTeach((int)$row['course_id'])) {
            wp_die(esc_html__('Certificate not found.', 'sepo-lms'));
        }
        $msg = '';
        if ($do === 'revoke' || $do === 'restore') {
            Certificates::setRevoked($id, $do === 'revoke');
            $msg = $do === 'revoke' ? 'revoked' : 'restored';
        } elseif ($do === 'delete') {
            Certificates::delete($id);
            $msg = 'deleted';
        }
        wp_safe_redirect(self::url(['msg' => $msg]));
        exit;
    }
}
