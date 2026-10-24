<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\Helpers;
use SepoLms\PostTypes;
use SepoLms\Quiz;
use SepoLms\QuizResults;

/**
 * LMS → Quiz results: every saved result, per quiz statistics, a
 * per-learner answer view, and deleting a result so a learner can retake.
 */
final class ResultsPage
{
    public const SLUG = 'sepo-lms-results';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 16);
        add_action('admin_post_slms_result_delete', [self::class, 'delete']);
    }

    public static function menu(): void
    {
        add_submenu_page(Menu::SLUG, __('Quiz results', 'sepo-lms'), __('Quiz results', 'sepo-lms'), Activator::CAP, self::SLUG, [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to see quiz results.', 'sepo-lms'));
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $view = isset($_GET['result']) ? absint($_GET['result']) : 0;
        $quizId = isset($_GET['quiz']) ? absint($_GET['quiz']) : 0;
        $courseId = isset($_GET['course']) ? absint($_GET['course']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string)$_GET['s'])) : '';
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash((string)$_GET['orderby'])) : 'submitted_at';
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash((string)$_GET['order'])) : 'desc';
        $done = isset($_GET['slms_done']);
        // phpcs:enable

        echo '<div class="wrap slms-admin">';
        if ($view > 0) {
            self::single($view);
            echo '</div>';
            return;
        }

        echo '<h1>' . esc_html__('Quiz results', 'sepo-lms') . '</h1>';
        if ($done) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Result deleted. The learner can take the quiz again.', 'sepo-lms') . '</p></div>';
        }

        $quizzes = get_posts(['post_type' => PostTypes::QUIZ, 'post_status' => 'any', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        $courses = get_posts(['post_type' => PostTypes::COURSE, 'post_status' => 'any', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        ?>
        <form method="get" class="slms-filterbar">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <select name="course"><option value=""><?php esc_html_e('All courses', 'sepo-lms'); ?></option>
                <?php foreach ($courses as $c): ?><option value="<?php echo (int)$c->ID; ?>" <?php selected($courseId, $c->ID); ?>><?php echo esc_html(get_the_title($c)); ?></option><?php endforeach; ?>
            </select>
            <select name="quiz"><option value=""><?php esc_html_e('All quizzes', 'sepo-lms'); ?></option>
                <?php foreach ($quizzes as $q): ?><option value="<?php echo (int)$q->ID; ?>" <?php selected($quizId, $q->ID); ?>><?php echo esc_html(get_the_title($q)); ?></option><?php endforeach; ?>
            </select>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Learner name or email', 'sepo-lms'); ?>">
            <?php submit_button(__('Filter', 'sepo-lms'), '', '', false); ?>
        </form>
        <?php

        if ($quizId > 0) {
            $st = QuizResults::quizStats($quizId);
            echo '<div class="slms-tiles">';
            foreach ([
                [__('Learners', 'sepo-lms'), (string)$st['learners']],
                [__('Attempts', 'sepo-lms'), (string)$st['attempts']],
                [__('Average', 'sepo-lms'), Helpers::percent((float)$st['average_score']) . '%'],
                [__('Pass rate', 'sepo-lms'), Helpers::percent((float)$st['pass_rate']) . '%'],
                [__('Highest', 'sepo-lms'), $st['highest_score'] . '%'],
                [__('Lowest', 'sepo-lms'), $st['lowest_score'] . '%'],
            ] as [$label, $value]) {
                echo '<div class="slms-tile"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>';
            }
            echo '</div>';
        }

        $per = 25;
        $res = QuizResults::query($quizId, $courseId, $search, $per, $paged, $orderby, $order);
        $sortLink = static function (string $col, string $label) use ($orderby, $order): string {
            $next = ($orderby === $col && $order === 'desc') ? 'asc' : 'desc';
            return '<a href="' . esc_url(add_query_arg(['orderby' => $col, 'order' => $next])) . '">' . esc_html($label) . ($orderby === $col ? ($order === 'desc' ? ' ↓' : ' ↑') : '') . '</a>';
        };
        ?>
        <table class="widefat striped slms-results-table">
            <thead><tr>
                <th><?php esc_html_e('Learner', 'sepo-lms'); ?></th>
                <th><?php esc_html_e('Quiz', 'sepo-lms'); ?></th>
                <th><?php echo $sortLink('score', __('Score', 'sepo-lms')); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
                <th><?php esc_html_e('Correct', 'sepo-lms'); ?></th>
                <th><?php echo $sortLink('attempt', __('Attempts', 'sepo-lms')); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
                <th><?php echo $sortLink('submitted_at', __('Submitted', 'sepo-lms')); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php if ($res['rows'] === []): ?>
                <tr><td colspan="7"><?php esc_html_e('No results yet.', 'sepo-lms'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($res['rows'] as $r): ?>
                <tr>
                    <td>
                        <?php if ($r['user_id'] > 0): ?>
                            <strong><?php echo esc_html((string)($r['display_name'] ?? '')); ?></strong><br><span class="description"><?php echo esc_html((string)($r['user_email'] ?? '')); ?></span>
                        <?php else: ?>
                            <em><?php esc_html_e('Guest', 'sepo-lms'); ?></em><?php echo $r['email'] !== '' ? '<br><span class="description">' . esc_html((string)$r['email']) . '</span>' : ''; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(get_the_title($r['quiz_id'])); ?><?php echo $r['course_id'] > 0 ? '<br><span class="description">' . esc_html(get_the_title($r['course_id'])) . '</span>' : ''; ?></td>
                    <td><span class="slms-st slms-st--<?php echo $r['passed'] ? 'paid' : 'fail'; ?>"><?php echo (int)$r['score']; ?>%</span><?php echo $r['best_score'] > $r['score'] ? ' <span class="description">' . esc_html(sprintf(/* translators: %d: score */ __('best %d%%', 'sepo-lms'), $r['best_score'])) . '</span>' : ''; ?></td>
                    <td><?php echo (int)$r['correct']; ?> / <?php echo (int)$r['total_questions']; ?></td>
                    <td><?php echo (int)$r['attempt']; ?></td>
                    <td><?php echo esc_html(Helpers::datetime((string)$r['submitted_at'])); ?></td>
                    <td class="slms-nowrap">
                        <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'result' => $r['id']], admin_url('admin.php'))); ?>"><?php esc_html_e('Answers', 'sepo-lms'); ?></a>
                        <a class="button button-small button-link-delete" data-slms-confirm="deleteResult" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=slms_result_delete&id=' . $r['id']), 'slms_result_delete_' . $r['id'])); ?>"><?php esc_html_e('Delete', 'sepo-lms'); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $pages = (int)ceil($res['total'] / $per);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</div>';
    }

    private static function single(int $id): void
    {
        $r = QuizResults::find($id);
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">← ' . esc_html__('All results', 'sepo-lms') . '</a></p>';
        if ($r === null) {
            echo '<p>' . esc_html__('Result not found.', 'sepo-lms') . '</p>';
            return;
        }
        $user = $r['user_id'] > 0 ? get_userdata($r['user_id']) : false;
        echo '<h1>' . esc_html(get_the_title($r['quiz_id'])) . '</h1>';
        echo '<p><strong>' . esc_html($user ? $user->display_name : __('Guest', 'sepo-lms')) . '</strong> · '
            . esc_html($user ? $user->user_email : (string)$r['email']) . ' · '
            . esc_html(sprintf(/* translators: 1: score, 2: correct, 3: total, 4: attempt */ __('%1$d%% · %2$d / %3$d correct · attempt %4$d', 'sepo-lms'), $r['score'], $r['correct'], $r['total_questions'], $r['attempt'])) . ' · '
            . esc_html(Helpers::datetime((string)$r['submitted_at'])) . '</p>';
        echo '<div class="slms-answers">';
        foreach (Quiz::review($r['quiz_id'], $r, true) as $q) {
            echo '<div class="slms-answer ' . ($q['is_correct'] ? 'is-correct' : 'is-wrong') . '">'
                . '<strong>Q' . (int)$q['n'] . '. ' . esc_html($q['question']) . '</strong> <span class="description">(' . esc_html(Quiz::formatPoints((float)$q['points'])) . ')</span>'
                . '<div>' . esc_html__('Answer', 'sepo-lms') . ': ' . esc_html($q['answered'] ? $q['user'] : __('Not answered', 'sepo-lms')) . '</div>'
                . ($q['correct'] !== '' ? '<div class="description">' . esc_html__('Expected', 'sepo-lms') . ': ' . esc_html($q['correct']) . '</div>' : '')
                . '</div>';
        }
        echo '</div>';
    }

    public static function delete(): void
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('slms_result_delete_' . $id);
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to do that.', 'sepo-lms'), '', ['response' => 403]);
        }
        QuizResults::delete($id);
        wp_safe_redirect(add_query_arg('slms_done', '1', wp_get_referer() ?: admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }
}
