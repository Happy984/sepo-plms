<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Access;
use SepoLms\Assignments;
use SepoLms\Enrollments;
use SepoLms\Helpers;
use SepoLms\PostTypes;
use SepoLms\Proctor;
use SepoLms\Settings;
use SepoLms\Submissions;

/**
 * LMS → Submissions: hand-ins to grade, and the grading screen.
 *
 * Staff see the courses they can edit or are linked to as the teacher.
 */
final class SubmissionsPage
{
    public const SLUG = 'sepo-lms-submissions';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 22);
    }

    public static function menu(): void
    {
        if (!Settings::feature('assignments')) {
            return;
        }
        $waiting = Submissions::counts(Access::teachableCourses(get_current_user_id()))['submitted'];
        $label = __('Submissions', 'sepo-lms') . ($waiting > 0 ? ' <span class="awaiting-mod count-' . (int)$waiting . '"><span class="pending-count">' . (int)$waiting . '</span></span>' : '');
        add_submenu_page(Menu::SLUG, __('Submissions', 'sepo-lms'), $label, 'edit_posts', self::SLUG, [self::class, 'render']);
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    /** @param list<int> $courses */
    public static function courseSelect(array $courses, int $current): void
    {
        echo '<label class="screen-reader-text" for="slms-f-course">' . esc_html__('Course', 'sepo-lms') . '</label><select name="course" id="slms-f-course"><option value="0">' . esc_html__('All my courses', 'sepo-lms') . '</option>';
        foreach ($courses as $id) {
            echo '<option value="' . (int)$id . '" ' . selected($current, $id, false) . '>' . esc_html(get_the_title($id)) . '</option>';
        }
        echo '</select>';
    }

    public static function pager(int $total, int $per, int $page): void
    {
        $pages = (int)ceil($total / $per);
        if ($pages <= 1) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([ // phpcs:ignore WordPress.Security.EscapeOutput
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $page,
            'total'   => $pages,
        ]) . '</div></div>';
    }

    public static function render(): void
    {
        $courses = Access::teachableCourses(get_current_user_id());
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($id > 0) {
            self::renderOne($id, $courses);
            return;
        }
        $course = isset($_GET['course']) ? absint($_GET['course']) : 0;
        $assignment = isset($_GET['assignment']) ? absint($_GET['assignment']) : 0;
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash((string)$_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string)$_GET['s'])) : '';
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        // phpcs:enable

        $scope = $course > 0 && in_array($course, $courses, true) ? [$course] : $courses;
        $assignments = [];
        foreach ($scope as $cid) {
            foreach (\SepoLms\Course::items($cid, true) as $p) {
                if ($p->post_type === PostTypes::ASSIGNMENT) {
                    $assignments[$p->ID] = get_the_title($p) . (count($scope) > 1 ? ' — ' . get_the_title($cid) : '');
                }
            }
        }
        $res = Submissions::query(['course_ids' => $scope, 'assignment' => $assignment, 'status' => $status, 'search' => $search], 25, $page);
        $counts = Submissions::counts($scope);
        ?>
        <div class="wrap slms-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('Submissions', 'sepo-lms'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . PostTypes::ASSIGNMENT)); ?>"><?php esc_html_e('Add assignment', 'sepo-lms'); ?></a>
            <hr class="wp-header-end">
            <ul class="subsubsub">
                <?php
                $views = ['' => __('All', 'sepo-lms'), 'submitted' => __('To grade', 'sepo-lms'), 'graded' => __('Graded', 'sepo-lms'), 'returned' => __('Returned', 'sepo-lms'), 'late' => __('Late', 'sepo-lms')];
                $links = [];
                foreach ($views as $k => $l) {
                    $n = $k === '' ? array_sum($counts) : ($counts[$k] ?? null);
                    $links[] = '<li><a href="' . esc_url(self::url(['status' => $k, 'course' => $course, 'assignment' => $assignment])) . '"' . ($status === $k ? ' class="current"' : '') . '>' . esc_html($l) . ($n !== null ? ' <span class="count">(' . (int)$n . ')</span>' : '') . '</a></li>';
                }
                echo implode(' | ', $links); // phpcs:ignore WordPress.Security.EscapeOutput
                ?>
            </ul>
            <form method="get" class="slms-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                <?php self::courseSelect($courses, $course); ?>
                <label class="screen-reader-text" for="slms-f-assignment"><?php esc_html_e('Assignment', 'sepo-lms'); ?></label>
                <select name="assignment" id="slms-f-assignment">
                    <option value="0"><?php esc_html_e('All assignments', 'sepo-lms'); ?></option>
                    <?php foreach ($assignments as $aid => $title): ?>
                        <option value="<?php echo (int)$aid; ?>" <?php selected($assignment, $aid); ?>><?php echo esc_html($title); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Learner name or email', 'sepo-lms'); ?>">
                <button class="button"><?php esc_html_e('Filter', 'sepo-lms'); ?></button>
            </form>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Learner', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Assignment', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Handed in', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Attempt', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Status', 'sepo-lms'); ?></th>
                    <th><?php esc_html_e('Grade', 'sepo-lms'); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php if ($res['rows'] === []): ?>
                    <tr><td colspan="7"><?php esc_html_e('Nothing here yet.', 'sepo-lms'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($res['rows'] as $r): ?>
                    <?php $s = Assignments::settings((int)$r['assignment_id']); ?>
                    <tr>
                        <td><strong><?php echo esc_html((string)($r['display_name'] ?? '#' . $r['user_id'])); ?></strong><br><span class="description"><?php echo esc_html((string)($r['user_email'] ?? '')); ?></span></td>
                        <td><?php echo esc_html($s['title']); ?><br><span class="description"><?php echo esc_html(get_the_title((int)$r['course_id'])); ?></span></td>
                        <td><?php echo esc_html(Helpers::datetime((string)$r['submitted_at'])); ?>
                            <?php if ($r['late']): ?><br><span class="slms-st slms-st--failed"><?php echo esc_html(sprintf(/* translators: %d: days */ _n('%d day late', '%d days late', $r['late_days'], 'sepo-lms'), $r['late_days'])); ?></span><?php endif; ?></td>
                        <td><?php echo (int)$r['attempt']; ?></td>
                        <td><span class="slms-st slms-st--<?php echo esc_attr($r['status'] === 'graded' ? 'paid' : ($r['status'] === 'returned' ? 'failed' : 'pending')); ?>"><?php echo esc_html(Submissions::statusLabel((string)$r['status'])); ?></span></td>
                        <td><?php echo $r['score'] !== null ? esc_html(Helpers::percent((float)$r['score']) . '%') : '—'; ?></td>
                        <td><a class="button" href="<?php echo esc_url(self::url(['id' => $r['id']])); ?>"><?php echo $r['status'] === 'submitted' ? esc_html__('Grade', 'sepo-lms') : esc_html__('Open', 'sepo-lms'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php self::pager($res['total'], 25, $page); ?>

            <?php if ($assignment > 0 && isset($assignments[$assignment])): ?>
                <?php self::missing($assignment); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Learners in the course who have not handed in the chosen assignment. */
    private static function missing(int $assignmentId): void
    {
        $s = Assignments::settings($assignmentId);
        if ($s['course_id'] <= 0) {
            return;
        }
        $missing = [];
        foreach (Enrollments::learners($s['course_id']) as $l) {
            if (Submissions::get($assignmentId, $l['user_id']) === null) {
                $missing[] = $l;
            }
        }
        echo '<h2>' . esc_html(sprintf(/* translators: %d: learners */ _n('%d learner has not handed in', '%d learners have not handed in', count($missing), 'sepo-lms'), count($missing))) . '</h2>';
        if ($missing !== []) {
            echo '<p>' . esc_html(implode(', ', array_map(static fn($l) => $l['name'], $missing))) . '</p>';
        }
    }

    /** @param list<int> $courses */
    private static function renderOne(int $id, array $courses): void
    {
        $r = Submissions::find($id);
        if ($r === null || !in_array((int)$r['course_id'], $courses, true)) {
            wp_die(esc_html__('Submission not found.', 'sepo-lms'));
        }
        $s = Assignments::settings((int)$r['assignment_id']);
        $u = get_userdata((int)$r['user_id']);
        $proctor = $r['proctor_id'] > 0 ? Proctor::find($r['proctor_id']) : null;
        ?>
        <div class="wrap slms-admin">
            <p><a href="<?php echo esc_url(self::url(['assignment' => $r['assignment_id']])); ?>">← <?php esc_html_e('All submissions', 'sepo-lms'); ?></a></p>
            <h1><?php echo esc_html($s['title']); ?></h1>
            <p class="description">
                <?php echo esc_html(get_the_title((int)$r['course_id'])); ?> ·
                <?php echo esc_html($u ? $u->display_name . ' <' . $u->user_email . '>' : '#' . $r['user_id']); ?>
            </p>

            <div class="slms-grade-layout">
                <div class="slms-panel-box">
                    <h2><?php esc_html_e('Hand-in', 'sepo-lms'); ?></h2>
                    <ul class="slms-proctor-facts">
                        <li><span><?php esc_html_e('Handed in', 'sepo-lms'); ?></span><strong><?php echo esc_html(Helpers::datetime((string)$r['submitted_at'])); ?></strong></li>
                        <li><span><?php esc_html_e('Due', 'sepo-lms'); ?></span><strong><?php echo esc_html(Assignments::dueLabel($s) ?: '—'); ?></strong></li>
                        <li><span><?php esc_html_e('Late', 'sepo-lms'); ?></span><strong class="<?php echo $r['late'] ? 'slms-bad' : ''; ?>"><?php echo $r['late'] ? esc_html(sprintf(/* translators: %d: days */ _n('%d day', '%d days', $r['late_days'], 'sepo-lms'), $r['late_days'])) : esc_html__('No', 'sepo-lms'); ?></strong></li>
                        <li><span><?php esc_html_e('Attempt', 'sepo-lms'); ?></span><strong><?php echo (int)$r['attempt']; ?></strong></li>
                    </ul>
                    <?php if ($r['files'] !== []): ?>
                        <h3><?php esc_html_e('Files', 'sepo-lms'); ?></h3>
                        <ul class="slms-files">
                            <?php foreach ($r['files'] as $i => $f): ?>
                                <?php $kind = \SepoLms\Downloads::viewKind((string)$f['name']); ?>
                                <li><span class="dashicons dashicons-media-default" aria-hidden="true"></span>
                                    <span class="slms-file-name"><?php echo esc_html((string)$f['name']); ?></span>
                                    <span class="description"><?php echo esc_html(size_format((int)$f['size'])); ?></span>
                                    <?php if ($kind !== ''): ?>
                                        <button type="button" class="button button-small" data-slms-view="<?php echo esc_url(Submissions::fileUrl($r, (int)$i, true)); ?>" data-kind="<?php echo esc_attr($kind); ?>" data-name="<?php echo esc_attr((string)$f['name']); ?>" aria-expanded="false"><?php esc_html_e('View', 'sepo-lms'); ?></button>
                                    <?php endif; ?>
                                    <a class="button button-small" href="<?php echo esc_url(Submissions::fileUrl($r, (int)$i)); ?>"><?php esc_html_e('Download', 'sepo-lms'); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($r['body'] !== ''): ?>
                        <h3><?php esc_html_e('Written answer', 'sepo-lms'); ?> <span class="description">(<?php echo esc_html(sprintf(/* translators: %d: words */ _n('%d word', '%d words', Assignments::wordCount($r['body']), 'sepo-lms'), Assignments::wordCount($r['body']))); ?>)</span></h3>
                        <div class="slms-answer"><?php echo wpautop(esc_html($r['body'])); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                    <?php endif; ?>
                    <?php if ($s['requirements'] !== []): ?>
                        <h3><?php esc_html_e('Requirements', 'sepo-lms'); ?></h3>
                        <ul class="ul-disc"><?php foreach ($s['requirements'] as $req): ?><li><?php echo esc_html($req); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </div>

                <div class="slms-panel-box">
                    <h2><?php esc_html_e('Grade', 'sepo-lms'); ?></h2>
                    <form data-slms-grade="<?php echo (int)$r['id']; ?>">
                        <p class="slms-f"><label for="slms-points"><?php echo esc_html(sprintf(/* translators: %s: max points */ __('Points (out of %s)', 'sepo-lms'), Helpers::percent($s['points']))); ?></label>
                            <input type="number" id="slms-points" name="points" min="0" max="<?php echo esc_attr((string)$s['points']); ?>" step="0.5" value="<?php echo $r['points'] !== null ? esc_attr((string)$r['points']) : ''; ?>" class="small-text"></p>
                        <?php if ($r['late'] && $s['late_penalty'] > 0): ?>
                            <p class="description"><?php echo esc_html(sprintf(
                                /* translators: 1: penalty per day, 2: days late, 3: total */
                                __('Late penalty: %1$s points per day × %2$d days = %3$s percentage points off.', 'sepo-lms'),
                                Helpers::percent($s['late_penalty']),
                                $r['late_days'],
                                Helpers::percent($s['late_penalty'] * $r['late_days'])
                            )); ?></p>
                        <?php endif; ?>
                        <p class="slms-f"><label for="slms-feedback"><?php esc_html_e('Feedback for the learner', 'sepo-lms'); ?></label>
                            <textarea id="slms-feedback" name="feedback" rows="7" class="widefat"><?php echo esc_textarea($r['feedback']); ?></textarea></p>
                        <p>
                            <button type="submit" class="button button-primary" data-action="grade"><?php esc_html_e('Save grade and notify', 'sepo-lms'); ?></button>
                            <button type="submit" class="button" data-action="return"><?php esc_html_e('Return for rework', 'sepo-lms'); ?></button>
                        </p>
                        <p class="description" data-slms-grade-status role="status">
                            <?php if ($r['status'] === 'graded'): ?>
                                <?php echo esc_html(sprintf(/* translators: 1: score, 2: date */ __('Graded %1$s%% on %2$s.', 'sepo-lms'), Helpers::percent((float)$r['score']), Helpers::datetime((string)$r['graded_at']))); ?>
                            <?php elseif ($r['status'] === 'returned'): ?>
                                <?php esc_html_e('Returned for rework. The learner can hand in again.', 'sepo-lms'); ?>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
            </div>

            <?php if ($proctor !== null): ?>
                <h2><?php esc_html_e('Proctoring', 'sepo-lms'); ?></h2>
                <?php ProctorPage::viewer($proctor); ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
