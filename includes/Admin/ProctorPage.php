<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Access;
use SepoLms\Helpers;
use SepoLms\Proctor;
use SepoLms\Settings;

/**
 * LMS → Proctoring: every proctored session, with its recording and a
 * timeline of what happened.
 *
 * A recording can be deleted on its own — one session at a time from the
 * session or submission screen, or several at once from the list — to free
 * space. The session, its timeline and the learner's submission and grade
 * are kept.
 */
final class ProctorPage
{
    public const SLUG = 'sepo-lms-proctoring';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 24);
        add_action('admin_post_slms_proctor_delete', [self::class, 'handleDelete']);
        add_action('admin_post_slms_proctor_delete_recording', [self::class, 'handleDeleteRecording']);
        add_action('admin_post_slms_proctor_delete_recordings', [self::class, 'handleDeleteRecordings']);
    }

    public static function menu(): void
    {
        if (!Settings::feature('proctoring')) {
            return;
        }
        add_submenu_page(Menu::SLUG, __('Proctoring', 'sepo-lms'), __('Proctoring', 'sepo-lms'), 'edit_posts', self::SLUG, [self::class, 'render']);
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    public static function render(): void
    {
        $courses = Access::teachableCourses(get_current_user_id());
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($id > 0) {
            $session = Proctor::find($id);
            if ($session === null || !in_array($session['course_id'], $courses, true)) {
                wp_die(esc_html__('Session not found.', 'sepo-lms'));
            }
            echo '<div class="wrap slms-admin"><p><a href="' . esc_url(self::url()) . '">← ' . esc_html__('All sessions', 'sepo-lms') . '</a></p>';
            $u = get_userdata($session['user_id']);
            echo '<h1>' . esc_html(sprintf(/* translators: 1: learner, 2: item */ __('%1$s — %2$s', 'sepo-lms'), $u ? $u->display_name : '#' . $session['user_id'], get_the_title($session['item_id']))) . '</h1>';
            self::viewer($session);
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr((string)wp_json_encode(__('Delete this session and its recording?', 'sepo-lms'))) . ')">'
                . '<input type="hidden" name="action" value="slms_proctor_delete"><input type="hidden" name="id" value="' . (int)$session['id'] . '">';
            wp_nonce_field('slms_proctor_delete_' . $session['id']);
            echo '<p><button class="button-link button-link-delete">' . esc_html__('Delete session and recording', 'sepo-lms') . '</button></p></form></div>';
            return;
        }
        $course = isset($_GET['course']) ? absint($_GET['course']) : 0;
        $item = isset($_GET['item']) ? absint($_GET['item']) : 0;
        $flagged = !empty($_GET['flagged']);
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        // phpcs:enable
        $scope = $course > 0 && in_array($course, $courses, true) ? [$course] : $courses;
        $res = Proctor::query(['course_ids' => $scope, 'item' => $item, 'flagged' => $flagged], 25, $page);
        ?>
        <div class="wrap slms-admin">
            <h1><?php esc_html_e('Proctoring', 'sepo-lms'); ?></h1>
            <?php self::notices(); ?>
            <p class="description"><?php esc_html_e('Sessions from proctored assignments and quizzes. A leave is a switch to another tab or window, leaving full screen, or stopping the screen share.', 'sepo-lms'); ?></p>
            <form method="get" class="slms-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <?php SubmissionsPage::courseSelect($courses, $course); ?>
                <label><input type="checkbox" name="flagged" value="1" <?php checked($flagged); ?>> <?php esc_html_e('Only sessions with leaves or auto-submission', 'sepo-lms'); ?></label>
                <button class="button"><?php esc_html_e('Filter', 'sepo-lms'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return this.querySelector('input[name=&quot;ids[]&quot;]:checked') ? confirm(<?php echo esc_attr((string)wp_json_encode(__('Delete the recordings of the selected sessions? The sessions, their timelines and the submissions are kept.', 'sepo-lms'))); ?>) : false">
            <input type="hidden" name="action" value="slms_proctor_delete_recordings">
            <input type="hidden" name="back" value="<?php echo esc_attr(self::currentUrl()); ?>">
            <?php wp_nonce_field('slms_proctor_delete_recordings'); ?>
            <table class="widefat striped">
                <thead><tr>
                    <td class="check-column"><input type="checkbox" aria-label="<?php esc_attr_e('Select all sessions with a recording', 'sepo-lms'); ?>" onclick="this.closest('table').querySelectorAll('input[name=&quot;ids[]&quot;]').forEach(function(c){c.checked=this.checked;},this)"></td>
                    <th><?php esc_html_e('Learner', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Assessment', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Started', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Duration', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Leaves', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Recording', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Status', 'sepo-lms'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ($res['rows'] === []): ?>
                    <tr><td colspan="8"><?php esc_html_e('No proctored sessions yet.', 'sepo-lms'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($res['rows'] as $r): ?>
                    <tr>
                        <th scope="row" class="check-column"><?php if ($r['bytes'] > 0): ?><input type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>" aria-label="<?php esc_attr_e('Select this session', 'sepo-lms'); ?>"><?php endif; ?></th>
                        <td><a href="<?php echo esc_url(self::url(['id' => $r['id']])); ?>"><strong><?php echo esc_html((string)($r['display_name'] ?? '#' . $r['user_id'])); ?></strong></a><br><span class="description"><?php echo esc_html((string)($r['user_email'] ?? '')); ?></span></td>
                        <td><?php echo esc_html(get_the_title($r['item_id'])); ?><br><span class="description"><?php echo esc_html($r['item_type'] === 'quiz' ? __('Quiz', 'sepo-lms') : __('Assignment', 'sepo-lms')); ?></span></td>
                        <td><?php echo esc_html(Helpers::datetime((string)$r['started'])); ?></td>
                        <td><?php echo esc_html(self::duration($r)); ?></td>
                        <td><?php echo $r['violations'] > 0 ? '<span class="slms-st slms-st--failed">' . (int)$r['violations'] . '</span>' : '0'; ?><?php echo $r['auto_submitted'] ? ' <span class="slms-st slms-st--failed">' . esc_html__('auto-submitted', 'sepo-lms') . '</span>' : ''; ?></td>
                        <td><?php echo $r['bytes'] > 0 ? esc_html(size_format($r['bytes'])) : ($r['recording'] ? esc_html(Proctor::recordingDeletion($r) !== null ? __('Deleted', 'sepo-lms') : __('None received', 'sepo-lms')) : '—'); ?></td>
                        <td><?php echo esc_html(self::statusLabel((string)$r['status'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="slms-bulk"><button type="submit" class="button"><?php esc_html_e('Delete recordings of selected sessions', 'sepo-lms'); ?></button>
                <span class="description"><?php esc_html_e('Frees the space the videos take. Sessions, timelines and submissions are kept.', 'sepo-lms'); ?></span></p>
            </form>
            <?php SubmissionsPage::pager($res['total'], 25, $page); ?>
        </div>
        <?php
    }

    /** Session summary, recording and timeline. Also used on the submission screen. */
    public static function viewer(array $session): void
    {
        $parts = Proctor::recordingParts($session);
        $start = (int)strtotime($session['started'] . ' UTC');
        self::notices();
        ?>
        <div class="slms-proctor">
            <ul class="slms-proctor-facts">
                <li><span><?php esc_html_e('Status', 'sepo-lms'); ?></span><strong><?php echo esc_html(self::statusLabel((string)$session['status'])); ?></strong></li>
                <li><span><?php esc_html_e('Started', 'sepo-lms'); ?></span><strong><?php echo esc_html(Helpers::datetime((string)$session['started'])); ?></strong></li>
                <li><span><?php esc_html_e('Duration', 'sepo-lms'); ?></span><strong><?php echo esc_html(self::duration($session)); ?></strong></li>
                <li><span><?php esc_html_e('Leaves', 'sepo-lms'); ?></span><strong class="<?php echo $session['violations'] > 0 ? 'slms-bad' : ''; ?>"><?php echo (int)$session['violations']; ?></strong></li>
                <li><span><?php esc_html_e('Auto-submitted', 'sepo-lms'); ?></span><strong><?php echo $session['auto_submitted'] ? esc_html__('Yes', 'sepo-lms') : esc_html__('No', 'sepo-lms'); ?></strong></li>
                <li><span><?php esc_html_e('Shared', 'sepo-lms'); ?></span><strong><?php echo esc_html($session['surface'] === 'monitor' ? __('Entire screen', 'sepo-lms') : ($session['surface'] !== '' ? $session['surface'] : '—')); ?></strong></li>
            </ul>

            <?php if ($session['recording']): ?>
                <h3><?php esc_html_e('Screen recording', 'sepo-lms'); ?></h3>
                <?php $gone = Proctor::recordingDeletion($session); ?>
                <?php if ($parts === [] && $gone !== null): ?>
                    <p class="description"><?php echo esc_html(sprintf(/* translators: 1: who or why, 2: date */ __('The recording was deleted (%1$s, %2$s). The session details and timeline below are kept.', 'sepo-lms'), $gone['detail'], wp_date((string)get_option('date_format') . ' ' . (string)get_option('time_format'), $gone['t']))); ?></p>
                <?php elseif ($parts === []): ?>
                    <p class="description"><?php echo esc_html($session['file'] === '' && $session['bytes'] === 0 && $session['chunks'] > 0 ? __('The recording was deleted after the retention period.', 'sepo-lms') : __('No recording reached the server.', 'sepo-lms')); ?></p>
                <?php else: ?>
                    <?php if (count($parts) > 1): ?>
                        <p class="description"><?php esc_html_e('Sharing restarted during the session, so the recording is in parts.', 'sepo-lms'); ?></p>
                    <?php endif; ?>
                    <div class="slms-videos">
                        <?php foreach ($parts as $p): ?>
                            <figure>
                                <video controls preload="metadata" src="<?php echo esc_url($p['url']); ?>"></video>
                                <figcaption><?php echo esc_html(sprintf(/* translators: 1: part number, 2: size */ __('Part %1$d · %2$s', 'sepo-lms'), $p['part'], size_format($p['size']))); ?> · <a href="<?php echo esc_url($p['url']); ?>" download><?php esc_html_e('Download', 'sepo-lms'); ?></a></figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                    <?php if (Access::canTeach((int)$session['course_id'])): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="slms-rec-delete" onsubmit="return confirm(<?php echo esc_attr((string)wp_json_encode(__('Delete this recording? The session details, timeline and the submission are kept. The video cannot be recovered.', 'sepo-lms'))); ?>)">
                            <input type="hidden" name="action" value="slms_proctor_delete_recording">
                            <input type="hidden" name="id" value="<?php echo (int)$session['id']; ?>">
                            <input type="hidden" name="back" value="<?php echo esc_attr(self::currentUrl()); ?>">
                            <?php wp_nonce_field('slms_proctor_delete_recording_' . $session['id']); ?>
                            <button type="submit" class="button"><?php echo esc_html(sprintf(/* translators: %s: size */ __('Delete recording only (%s)', 'sepo-lms'), size_format(array_sum(array_column($parts, 'size'))))); ?></button>
                            <span class="description"><?php esc_html_e('Keeps the session details, timeline, submission and grade.', 'sepo-lms'); ?></span>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <h3><?php esc_html_e('Timeline', 'sepo-lms'); ?></h3>
            <table class="widefat striped slms-timeline">
                <thead><tr><th><?php esc_html_e('Time', 'sepo-lms'); ?></th><th><?php esc_html_e('Into session', 'sepo-lms'); ?></th><th><?php esc_html_e('Event', 'sepo-lms'); ?></th><th><?php esc_html_e('Detail', 'sepo-lms'); ?></th></tr></thead>
                <tbody>
                <?php
                // The site's time format with seconds added after the minutes.
                $clock = (string)get_option('time_format');
                $clock = str_contains($clock, 'i') ? preg_replace('/i/', 'i:s', $clock, 1) : 'H:i:s';
                ?>
                <?php foreach ($session['events'] as $e): ?>
                    <?php $t = (int)($e['t'] ?? 0); $bad = in_array((string)($e['type'] ?? ''), Proctor::VIOLATIONS, true) || ($e['type'] ?? '') === 'auto_submit'; ?>
                    <tr class="<?php echo $bad ? 'slms-row-bad' : ''; ?>">
                        <td><?php echo esc_html(($e['type'] ?? '') === 'recording_deleted' ? wp_date((string)get_option('date_format') . ' ' . $clock, $t) : wp_date($clock, $t)); ?></td>
                        <td><?php echo ($e['type'] ?? '') === 'recording_deleted' ? '—' : esc_html(gmdate($t - $start >= HOUR_IN_SECONDS ? 'G:i:s' : 'i:s', max(0, $t - $start))); ?></td>
                        <td><?php echo esc_html(Proctor::eventLabel((string)($e['type'] ?? ''))); ?></td>
                        <td><?php echo esc_html((string)($e['detail'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active'    => __('In progress', 'sepo-lms'),
            'ended'     => __('Finished', 'sepo-lms'),
            'abandoned' => __('Left without finishing', 'sepo-lms'),
            default     => $status,
        };
    }

    public static function duration(array $s): string
    {
        $from = (int)strtotime($s['started'] . ' UTC');
        $to = (int)strtotime(($s['ended'] ?: $s['last_seen']) . ' UTC');
        $secs = max(0, $to - $from);
        return $secs >= HOUR_IN_SECONDS ? gmdate('G\h i\m', $secs) : sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
    }

    public static function handleDeleteRecording(): void
    {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer('slms_proctor_delete_recording_' . $id);
        $s = Proctor::find($id);
        if ($s === null || !Access::canTeach($s['course_id'])) {
            wp_die(esc_html__('Session not found.', 'sepo-lms'));
        }
        $done = Proctor::deleteRecording($s, wp_get_current_user()->display_name);
        wp_safe_redirect(add_query_arg('slms_rec', $done ? 'deleted' : 'none', self::backUrl(self::url(['id' => $id]))));
        exit;
    }

    public static function handleDeleteRecordings(): void
    {
        check_admin_referer('slms_proctor_delete_recordings');
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', wp_unslash($_POST['ids'])) : [];
        $by = wp_get_current_user()->display_name;
        $n = 0;
        foreach (array_unique(array_filter($ids)) as $id) {
            $s = Proctor::find($id);
            if ($s !== null && Access::canTeach($s['course_id']) && Proctor::deleteRecording($s, $by)) {
                $n++;
            }
        }
        wp_safe_redirect(add_query_arg(['slms_rec' => 'bulk', 'slms_n' => $n], self::backUrl(self::url())));
        exit;
    }

    /** Where to go after a delete: the page the button was on, when it is an admin page. */
    private static function backUrl(string $fallback): string
    {
        $back = isset($_POST['back']) ? esc_url_raw(wp_unslash((string)$_POST['back'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller
        $back = remove_query_arg(['slms_rec', 'slms_n', 'deleted'], $back);
        return $back !== '' && str_starts_with($back, admin_url()) ? $back : $fallback;
    }

    /** This admin page's own address, so a delete can bring the user back to it. */
    private static function currentUrl(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $host = (string)wp_parse_url(admin_url(), PHP_URL_HOST);
        $port = wp_parse_url(admin_url(), PHP_URL_PORT);
        return esc_url_raw((is_ssl() ? 'https://' : 'http://') . $host . ($port ? ':' . $port : '') . $uri);
    }

    private static function notices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- display only
        $rec = isset($_GET['slms_rec']) ? sanitize_key((string)$_GET['slms_rec']) : '';
        if ($rec === 'deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Recording deleted. The session, its timeline and the submission are kept.', 'sepo-lms') . '</p></div>';
        } elseif ($rec === 'bulk') {
            $n = isset($_GET['slms_n']) ? absint($_GET['slms_n']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(/* translators: %d: number of recordings */ _n('%d recording deleted. The sessions, timelines and submissions are kept.', '%d recordings deleted. The sessions, timelines and submissions are kept.', $n, 'sepo-lms'), $n)) . '</p></div>';
        } elseif ($rec === 'none') {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('That session has no recording to delete.', 'sepo-lms') . '</p></div>';
        } elseif (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Session and recording deleted.', 'sepo-lms') . '</p></div>';
        }
        // phpcs:enable
    }

    public static function handleDelete(): void
    {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer('slms_proctor_delete_' . $id);
        $s = Proctor::find($id);
        if ($s === null || !Access::canTeach($s['course_id'])) {
            wp_die(esc_html__('Session not found.', 'sepo-lms'));
        }
        Proctor::deleteSession($s);
        wp_safe_redirect(self::url(['deleted' => 1]));
        exit;
    }
}
