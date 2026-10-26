<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Access;
use SepoLms\Attendance;
use SepoLms\Enrollments;
use SepoLms\Settings;

/**
 * LMS → Attendance: sessions per course, the roster for marking, the check-in
 * code to show the class, and the course register with CSV export.
 */
final class AttendancePage
{
    public const SLUG = 'sepo-lms-attendance';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 23);
        add_action('admin_post_slms_att_session', [self::class, 'saveSession']);
        add_action('admin_post_slms_att_mark', [self::class, 'saveMarks']);
        add_action('admin_post_slms_att_delete', [self::class, 'deleteSession']);
        add_action('admin_post_slms_att_code', [self::class, 'newCode']);
        add_action('admin_post_slms_att_csv', [self::class, 'csv']);
    }

    public static function menu(): void
    {
        if (!Settings::feature('attendance')) {
            return;
        }
        add_submenu_page(Menu::SLUG, __('Attendance', 'sepo-lms'), __('Attendance', 'sepo-lms'), 'edit_posts', self::SLUG, [self::class, 'render']);
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    private static function guard(int $courseId): void
    {
        if ($courseId <= 0 || !Access::canTeach($courseId)) {
            wp_die(esc_html__('You cannot take attendance for this course.', 'sepo-lms'));
        }
    }

    public static function render(): void
    {
        $courses = Access::teachableCourses(get_current_user_id());
        // phpcs:disable WordPress.Security.NonceVerification -- read-only views
        $course = isset($_GET['course']) ? absint($_GET['course']) : ($courses[0] ?? 0);
        $session = isset($_GET['session']) ? absint($_GET['session']) : 0;
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string)$_GET['view'])) : '';
        $msg = isset($_GET['msg']) ? sanitize_key(wp_unslash((string)$_GET['msg'])) : '';
        $err = isset($_GET['err']) ? sanitize_text_field(wp_unslash((string)$_GET['err'])) : '';
        // phpcs:enable
        echo '<div class="wrap slms-admin"><h1>' . esc_html__('Attendance', 'sepo-lms') . '</h1>';
        if ($courses === []) {
            echo '<p>' . esc_html__('You are not staff on any course yet.', 'sepo-lms') . '</p></div>';
            return;
        }
        if (!in_array($course, $courses, true)) {
            $course = $courses[0];
        }
        $notices = ['saved' => __('Session saved.', 'sepo-lms'), 'marked' => __('Attendance saved.', 'sepo-lms'), 'deleted' => __('Session deleted.', 'sepo-lms'), 'code' => __('New code created.', 'sepo-lms')];
        if (isset($notices[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notices[$msg]) . '</p></div>';
        }
        if ($err !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
        }
        ?>
        <form method="get" class="slms-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <label class="screen-reader-text" for="slms-att-course"><?php esc_html_e('Course', 'sepo-lms'); ?></label>
            <select name="course" id="slms-att-course" onchange="this.form.submit()">
                <?php foreach ($courses as $cid): ?>
                    <option value="<?php echo (int)$cid; ?>" <?php selected($course, $cid); ?>><?php echo esc_html(get_the_title($cid)); ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="button"><?php esc_html_e('Show', 'sepo-lms'); ?></button></noscript>
            <a class="button" href="<?php echo esc_url(self::url(['course' => $course])); ?>"><?php esc_html_e('Sessions', 'sepo-lms'); ?></a>
            <a class="button" href="<?php echo esc_url(self::url(['course' => $course, 'view' => 'register'])); ?>"><?php esc_html_e('Register', 'sepo-lms'); ?></a>
        </form>
        <?php
        if ($session > 0) {
            self::renderSession($course, $session);
        } elseif ($view === 'register') {
            self::renderRegister($course);
        } else {
            self::renderSessions($course);
        }
        echo '</div>';
    }

    private static function renderSessions(int $course): void
    {
        $sessions = Attendance::sessions($course);
        $learners = count(Enrollments::learners($course));
        $fmt = (string)get_option('date_format') . ' ' . (string)get_option('time_format');
        $now = time();
        $defaultStart = wp_date('Y-m-d\TH:00', $now + HOUR_IN_SECONDS);
        $defaultEnd = wp_date('Y-m-d\TH:00', $now + 2 * HOUR_IN_SECONDS);
        ?>
        <div class="slms-grade-layout">
            <div class="slms-panel-box">
                <h2><?php esc_html_e('Sessions', 'sepo-lms'); ?></h2>
                <?php if ($sessions === []): ?>
                    <p class="description"><?php esc_html_e('No sessions yet. Create one to start taking attendance.', 'sepo-lms'); ?></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Session', 'sepo-lms'); ?></th><th><?php esc_html_e('When', 'sepo-lms'); ?></th><th><?php esc_html_e('Attended', 'sepo-lms'); ?></th><th><?php esc_html_e('Check-in', 'sepo-lms'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $s): ?>
                            <tr>
                                <td><a href="<?php echo esc_url(self::url(['course' => $course, 'session' => $s['id']])); ?>"><strong><?php echo esc_html($s['title']); ?></strong></a></td>
                                <td><?php echo esc_html(wp_date($fmt, $s['starts_ts'])); ?></td>
                                <td><?php echo esc_html($s['attended'] . ' / ' . $learners); ?></td>
                                <td><?php
                                    if (!$s['self_checkin']) {
                                        esc_html_e('Staff only', 'sepo-lms');
                                    } elseif (Attendance::isOpen($s, $now)) {
                                        echo '<span class="slms-st slms-st--paid">' . esc_html__('Open now', 'sepo-lms') . '</span>';
                                    } elseif ($s['ends_ts'] < $now) {
                                        esc_html_e('Closed', 'sepo-lms');
                                    } else {
                                        esc_html_e('Not open yet', 'sepo-lms');
                                    }
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="slms-panel-box">
                <h2><?php esc_html_e('New session', 'sepo-lms'); ?></h2>
                <?php self::sessionForm($course, null, $defaultStart, $defaultEnd); ?>
            </div>
        </div>
        <?php
    }

    private static function sessionForm(int $course, ?array $s, string $start, string $end): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="slms_att_session">
            <input type="hidden" name="course" value="<?php echo (int)$course; ?>">
            <input type="hidden" name="session" value="<?php echo (int)($s['id'] ?? 0); ?>">
            <?php wp_nonce_field('slms_att_session_' . $course); ?>
            <p class="slms-f"><label for="slms-att-title"><?php esc_html_e('Title', 'sepo-lms'); ?></label>
                <input type="text" id="slms-att-title" name="title" class="widefat" value="<?php echo esc_attr((string)($s['title'] ?? '')); ?>" placeholder="<?php esc_attr_e('Week 3 lecture', 'sepo-lms'); ?>"></p>
            <div class="slms-cols">
                <p class="slms-f"><label for="slms-att-start"><?php esc_html_e('Starts', 'sepo-lms'); ?></label>
                    <input type="datetime-local" id="slms-att-start" name="starts" class="widefat" required value="<?php echo esc_attr($s !== null ? Attendance::toLocalInput($s['starts']) : $start); ?>"></p>
                <p class="slms-f"><label for="slms-att-end"><?php esc_html_e('Ends', 'sepo-lms'); ?></label>
                    <input type="datetime-local" id="slms-att-end" name="ends" class="widefat" required value="<?php echo esc_attr($s !== null ? Attendance::toLocalInput($s['ends']) : $end); ?>"></p>
            </div>
            <p class="slms-f"><label for="slms-att-late"><?php esc_html_e('Late after (minutes)', 'sepo-lms'); ?></label>
                <input type="number" id="slms-att-late" name="late_after" min="0" max="600" class="small-text" value="<?php echo (int)($s['late_after'] ?? 10); ?>"></p>
            <p class="slms-f slms-f--check"><label><input type="checkbox" name="self_checkin" value="1" <?php checked($s === null || $s['self_checkin']); ?>> <?php esc_html_e('Learners can check themselves in from the course page', 'sepo-lms'); ?></label></p>
            <p class="slms-f slms-f--check"><label><input type="checkbox" name="use_code" value="1" <?php checked($s === null || $s['code'] !== ''); ?>> <?php esc_html_e('Require a code (show it to the class)', 'sepo-lms'); ?></label></p>
            <p class="description"><?php esc_html_e('Self check-in opens 15 minutes before the start and closes at the end.', 'sepo-lms'); ?></p>
            <p><button class="button button-primary"><?php echo $s === null ? esc_html__('Create session', 'sepo-lms') : esc_html__('Save session', 'sepo-lms'); ?></button></p>
        </form>
        <?php
    }

    private static function renderSession(int $course, int $id): void
    {
        $s = Attendance::session($id);
        if ($s === null || $s['course_id'] !== $course) {
            echo '<p>' . esc_html__('Session not found.', 'sepo-lms') . '</p>';
            return;
        }
        $learners = Enrollments::learners($course);
        $records = Attendance::records($id);
        $labels = Attendance::statusLabels();
        $fmt = (string)get_option('date_format') . ' ' . (string)get_option('time_format');
        $ended = $s['ends_ts'] < time();
        ?>
        <p><a href="<?php echo esc_url(self::url(['course' => $course])); ?>">← <?php esc_html_e('All sessions', 'sepo-lms'); ?></a></p>
        <h2><?php echo esc_html($s['title']); ?> <span class="description"><?php echo esc_html(wp_date($fmt, $s['starts_ts']) . ' – ' . wp_date((string)get_option('time_format'), $s['ends_ts'])); ?></span></h2>

        <?php if ($s['self_checkin'] && $s['code'] !== ''): ?>
            <div class="slms-code-card">
                <span><?php esc_html_e('Check-in code', 'sepo-lms'); ?></span>
                <strong><?php echo esc_html($s['code']); ?></strong>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="slms_att_code"><input type="hidden" name="session" value="<?php echo (int)$id; ?>">
                    <?php wp_nonce_field('slms_att_code_' . $id); ?>
                    <button class="button-link"><?php esc_html_e('Make a new code', 'sepo-lms'); ?></button>
                </form>
            </div>
        <?php endif; ?>

        <div class="slms-grade-layout">
            <div class="slms-panel-box">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="slms_att_mark">
                    <input type="hidden" name="session" value="<?php echo (int)$id; ?>">
                    <?php wp_nonce_field('slms_att_mark_' . $id); ?>
                    <p class="slms-bulk">
                        <?php esc_html_e('Mark everyone without a status as:', 'sepo-lms'); ?>
                        <?php foreach ($labels as $k => $l): ?>
                            <button type="button" class="button button-small" data-slms-att-fill="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></button>
                        <?php endforeach; ?>
                    </p>
                    <table class="widefat striped slms-roster">
                        <thead><tr><th><?php esc_html_e('Learner', 'sepo-lms'); ?></th>
                            <?php foreach ($labels as $l): ?><th class="slms-c"><?php echo esc_html($l); ?></th><?php endforeach; ?>
                            <th><?php esc_html_e('Note', 'sepo-lms'); ?></th></tr></thead>
                        <tbody>
                        <?php if ($learners === []): ?>
                            <tr><td colspan="6"><?php esc_html_e('No learners in this course yet.', 'sepo-lms'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($learners as $l): ?>
                            <?php $rec = $records[$l['user_id']] ?? null; $cur = $rec['status'] ?? ''; ?>
                            <tr data-slms-att-row>
                                <td><strong><?php echo esc_html($l['name']); ?></strong>
                                    <?php if ($rec !== null && $rec['method'] === 'self'): ?><br><span class="description"><?php echo esc_html(sprintf(/* translators: %s: time */ __('Checked in at %s', 'sepo-lms'), get_date_from_gmt($rec['marked_at'], (string)get_option('time_format')))); ?></span><?php endif; ?>
                                    <?php if ($cur === '' && $ended): ?><br><span class="description"><?php esc_html_e('No record: counts as absent', 'sepo-lms'); ?></span><?php endif; ?>
                                </td>
                                <?php foreach (array_keys($labels) as $k): ?>
                                    <td class="slms-c"><input type="radio" name="mark[<?php echo (int)$l['user_id']; ?>]" value="<?php echo esc_attr($k); ?>" <?php checked($cur, $k); ?> aria-label="<?php echo esc_attr($labels[$k] . ' — ' . $l['name']); ?>"></td>
                                <?php endforeach; ?>
                                <td><input type="text" name="note[<?php echo (int)$l['user_id']; ?>]" value="<?php echo esc_attr((string)($rec['note'] ?? '')); ?>" class="regular-text"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button class="button button-primary"><?php esc_html_e('Save attendance', 'sepo-lms'); ?></button></p>
                </form>
            </div>
            <div class="slms-panel-box">
                <h3><?php esc_html_e('Edit session', 'sepo-lms'); ?></h3>
                <?php self::sessionForm($course, $s, '', ''); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo esc_attr((string)wp_json_encode(__('Delete this session and its attendance records?', 'sepo-lms'))); ?>)">
                    <input type="hidden" name="action" value="slms_att_delete"><input type="hidden" name="session" value="<?php echo (int)$id; ?>">
                    <?php wp_nonce_field('slms_att_delete_' . $id); ?>
                    <p><button class="button-link button-link-delete"><?php esc_html_e('Delete session', 'sepo-lms'); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function renderRegister(int $course): void
    {
        $r = Attendance::report($course);
        $short = ['present' => 'P', 'late' => 'L', 'absent' => 'A', 'excused' => 'E'];
        ?>
        <h2><?php esc_html_e('Register', 'sepo-lms'); ?>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'slms_att_csv', 'course' => $course], admin_url('admin-post.php')), 'slms_att_csv_' . $course)); ?>"><?php esc_html_e('Download CSV', 'sepo-lms'); ?></a></h2>
        <p class="description"><?php esc_html_e('P present · L late · A absent · E excused. Attendance counts present and late; excused sessions are left out.', 'sepo-lms'); ?></p>
        <?php if ($r['sessions'] === [] || $r['learners'] === []): ?>
            <p><?php esc_html_e('Nothing to show yet.', 'sepo-lms'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <div class="slms-scroll-x">
            <table class="widefat striped slms-register">
                <thead><tr><th><?php esc_html_e('Learner', 'sepo-lms'); ?></th>
                    <?php foreach ($r['sessions'] as $s): ?><th class="slms-c" title="<?php echo esc_attr($s['title']); ?>"><?php echo esc_html(wp_date('j M', $s['starts_ts'])); ?></th><?php endforeach; ?>
                    <th class="slms-c"><?php esc_html_e('Rate', 'sepo-lms'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($r['learners'] as $l): ?>
                    <?php $row = $r['grid'][$l['user_id']] ?? []; $att = 0; $counted = 0; ?>
                    <tr><td><?php echo esc_html($l['name']); ?></td>
                        <?php foreach ($r['sessions'] as $s): ?>
                            <?php $st = $row[$s['id']] ?? ''; if (in_array($st, ['present', 'late', 'absent'], true)) { $counted++; } if (in_array($st, ['present', 'late'], true)) { $att++; } ?>
                            <td class="slms-c slms-att-<?php echo esc_attr($st); ?>"><?php echo esc_html($short[$st] ?? '·'); ?></td>
                        <?php endforeach; ?>
                        <td class="slms-c"><strong><?php echo $counted > 0 ? esc_html((string)round($att / $counted * 100)) . '%' : '—'; ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ------------------------------------------------------------ handlers

    public static function saveSession(): void
    {
        $course = isset($_POST['course']) ? absint($_POST['course']) : 0;
        check_admin_referer('slms_att_session_' . $course);
        self::guard($course);
        $id = isset($_POST['session']) ? absint($_POST['session']) : 0;
        if ($id > 0) {
            $existing = Attendance::session($id);
            if ($existing === null || $existing['course_id'] !== $course) {
                wp_die(esc_html__('Session not found.', 'sepo-lms'));
            }
        }
        try {
            $id = Attendance::save($course, [
                'title'        => isset($_POST['title']) ? wp_unslash((string)$_POST['title']) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in save()
                'starts'       => isset($_POST['starts']) ? sanitize_text_field(wp_unslash((string)$_POST['starts'])) : '',
                'ends'         => isset($_POST['ends']) ? sanitize_text_field(wp_unslash((string)$_POST['ends'])) : '',
                'late_after'   => isset($_POST['late_after']) ? absint($_POST['late_after']) : 10,
                'self_checkin' => !empty($_POST['self_checkin']),
                'use_code'     => !empty($_POST['use_code']),
            ], get_current_user_id(), $id);
        } catch (\DomainException $e) {
            wp_safe_redirect(self::url(['course' => $course, 'err' => $e->getMessage()]));
            exit;
        }
        wp_safe_redirect(self::url(['course' => $course, 'session' => $id, 'msg' => 'saved']));
        exit;
    }

    public static function saveMarks(): void
    {
        $id = isset($_POST['session']) ? absint($_POST['session']) : 0;
        check_admin_referer('slms_att_mark_' . $id);
        $s = Attendance::session($id);
        if ($s === null) {
            wp_die(esc_html__('Session not found.', 'sepo-lms'));
        }
        self::guard($s['course_id']);
        $marks = isset($_POST['mark']) && is_array($_POST['mark']) ? array_map('sanitize_key', wp_unslash($_POST['mark'])) : [];
        $notes = isset($_POST['note']) && is_array($_POST['note']) ? array_map('sanitize_text_field', wp_unslash($_POST['note'])) : [];
        $allowed = array_column(Enrollments::learners($s['course_id']), 'user_id');
        $records = Attendance::records($id);
        foreach ($allowed as $uid) {
            $status = $marks[$uid] ?? '';
            $note = $notes[$uid] ?? '';
            $old = $records[$uid] ?? null;
            if ($status === '' && $old === null) {
                continue;
            }
            if ($old !== null && $old['status'] === $status && $old['note'] === $note) {
                continue;
            }
            Attendance::mark($id, (int)$uid, $status, $note, get_current_user_id(), $old !== null && $old['status'] === $status ? $old['method'] : 'teacher');
        }
        wp_safe_redirect(self::url(['course' => $s['course_id'], 'session' => $id, 'msg' => 'marked']));
        exit;
    }

    public static function deleteSession(): void
    {
        $id = isset($_POST['session']) ? absint($_POST['session']) : 0;
        check_admin_referer('slms_att_delete_' . $id);
        $s = Attendance::session($id);
        if ($s === null) {
            wp_die(esc_html__('Session not found.', 'sepo-lms'));
        }
        self::guard($s['course_id']);
        Attendance::delete($id);
        wp_safe_redirect(self::url(['course' => $s['course_id'], 'msg' => 'deleted']));
        exit;
    }

    public static function newCode(): void
    {
        $id = isset($_POST['session']) ? absint($_POST['session']) : 0;
        check_admin_referer('slms_att_code_' . $id);
        $s = Attendance::session($id);
        if ($s === null) {
            wp_die(esc_html__('Session not found.', 'sepo-lms'));
        }
        self::guard($s['course_id']);
        Attendance::newCodeFor($id);
        wp_safe_redirect(self::url(['course' => $s['course_id'], 'session' => $id, 'msg' => 'code']));
        exit;
    }

    public static function csv(): void
    {
        $course = isset($_GET['course']) ? absint($_GET['course']) : 0;
        check_admin_referer('slms_att_csv_' . $course);
        self::guard($course);
        $r = Attendance::report($course);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="attendance-' . sanitize_file_name(get_post_field('post_name', $course)) . '-' . wp_date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $head = [__('Learner', 'sepo-lms'), __('Email', 'sepo-lms')];
        foreach ($r['sessions'] as $s) {
            $head[] = $s['title'] . ' (' . wp_date('Y-m-d H:i', $s['starts_ts']) . ')';
        }
        $head[] = __('Attendance rate', 'sepo-lms');
        fputcsv($out, $head);
        $labels = Attendance::statusLabels();
        foreach ($r['learners'] as $l) {
            $row = [self::cell($l['name']), self::cell($l['email'])];
            $att = 0;
            $counted = 0;
            foreach ($r['sessions'] as $s) {
                $st = $r['grid'][$l['user_id']][$s['id']] ?? '';
                if (in_array($st, ['present', 'late', 'absent'], true)) {
                    $counted++;
                }
                if (in_array($st, ['present', 'late'], true)) {
                    $att++;
                }
                $row[] = $labels[$st] ?? '';
            }
            $row[] = $counted > 0 ? round($att / $counted * 100) . '%' : '';
            fputcsv($out, $row);
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /** Stops a spreadsheet reading a cell as a formula. */
    private static function cell(string $v): string
    {
        return $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
    }
}
