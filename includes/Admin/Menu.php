<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\Enrollments;
use SepoLms\Helpers;
use SepoLms\PostTypes;
use SepoLms\Receipt;

/**
 * The "LMS" admin menu and its overview page.
 */
final class Menu
{
    public const SLUG = 'sepo-lms';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'top'], 9);
        add_action('admin_menu', [self::class, 'extra'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('parent_file', [self::class, 'parentFile']);
    }

    public static function top(): void
    {
        add_menu_page(__('LMS', 'sepo-lms'), __('LMS', 'sepo-lms'), 'edit_posts', self::SLUG, [self::class, 'overview'], 'dashicons-welcome-learn-more', 26);
        add_submenu_page(self::SLUG, __('LMS overview', 'sepo-lms'), __('Overview', 'sepo-lms'), 'edit_posts', self::SLUG, [self::class, 'overview']);
    }

    public static function extra(): void
    {
        add_submenu_page(self::SLUG, __('Course categories', 'sepo-lms'), __('Categories', 'sepo-lms'), 'manage_categories', 'edit-tags.php?taxonomy=' . PostTypes::CATEGORY . '&post_type=' . PostTypes::COURSE);
    }

    /** Keep the LMS menu open on the category screen. */
    public static function parentFile(string $file): string
    {
        global $current_screen;
        if ($current_screen && $current_screen->taxonomy === PostTypes::CATEGORY) {
            return self::SLUG;
        }
        return $file;
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        $ours = str_contains($hook, 'sepo-lms')
            || ($screen && in_array($screen->post_type, [PostTypes::COURSE, PostTypes::LESSON, PostTypes::QUIZ, PostTypes::ASSIGNMENT, PostTypes::SCHOOL, PostTypes::TEACHER], true));
        if (!$ours) {
            return;
        }
        wp_enqueue_style('sepo-lms-admin', SLMS_URL . 'assets/css/admin.css', [], \SepoLms\Plugin::assetVersion('assets/css/admin.css'));
        wp_enqueue_script('sepo-lms-admin', SLMS_URL . 'assets/js/admin.js', ['jquery', 'jquery-ui-sortable'], \SepoLms\Plugin::assetVersion('assets/js/admin.js'), true);
        if (str_contains($hook, 'sepo-lms-submissions')) {
            \SepoLms\Frontend::registerViewer();
            wp_enqueue_script('sepo-lms-viewer');
        }
        wp_localize_script('sepo-lms-admin', 'SepoLmsAdmin', [
            'api'   => esc_url_raw(rest_url('sepo-lms/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n'  => [
                'confirmRemove' => __('Remove this enrolment? The learner loses access to the course.', 'sepo-lms'),
                'confirmPaid'   => __('Mark this enrolment as paid? A receipt is emailed to the learner.', 'sepo-lms'),
                'confirmDelete' => __('Delete this result? The learner will be able to take the quiz again.', 'sepo-lms'),
                'failed'        => __('That didn’t work. Try again.', 'sepo-lms'),
                'choose'        => __('Choose image', 'sepo-lms'),
                'use'           => __('Use this image', 'sepo-lms'),
                'saving'        => __('Saving…', 'sepo-lms'),
                /* translators: %s: score */
                'graded'        => __('Graded %s%%. The learner has been notified.', 'sepo-lms'),
                'returned'      => __('Returned for rework. The learner has been notified.', 'sepo-lms'),
            ],
        ]);
        if ($screen && (in_array($screen->base, ['post'], true) || str_contains($hook, 'sepo-lms-settings'))) {
            wp_enqueue_media();
        }
    }

    public static function overview(): void
    {
        $counts = Enrollments::statusCounts();
        $types = [
            PostTypes::COURSE  => __('Courses', 'sepo-lms'),
            PostTypes::LESSON  => __('Lessons', 'sepo-lms'),
            PostTypes::QUIZ    => __('Quizzes', 'sepo-lms'),
            PostTypes::ASSIGNMENT => __('Assignments', 'sepo-lms'),
            PostTypes::SCHOOL  => __('Schools', 'sepo-lms'),
            PostTypes::TEACHER => __('Teachers', 'sepo-lms'),
        ];
        $recent = current_user_can(Activator::CAP) ? Enrollments::query([], 8, 1)['rows'] : [];
        ?>
        <div class="wrap slms-admin">
            <h1><?php esc_html_e('LMS overview', 'sepo-lms'); ?></h1>

            <div class="slms-tiles">
                <?php foreach ($types as $type => $label): ?>
                    <?php $n = wp_count_posts($type); ?>
                    <a class="slms-tile" href="<?php echo esc_url(admin_url('edit.php?post_type=' . $type)); ?>">
                        <strong><?php echo (int)($n->publish ?? 0); ?></strong><span><?php echo esc_html($label); ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (current_user_can(Activator::CAP)): ?>
                    <a class="slms-tile" href="<?php echo esc_url(admin_url('admin.php?page=sepo-lms-enrollments')); ?>">
                        <strong><?php echo (int)array_sum($counts); ?></strong><span><?php esc_html_e('Enrolments', 'sepo-lms'); ?></span>
                    </a>
                    <a class="slms-tile<?php echo $counts['pending'] > 0 ? ' is-wait' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=sepo-lms-enrollments&status=pending')); ?>">
                        <strong><?php echo (int)$counts['pending']; ?></strong><span><?php esc_html_e('Awaiting payment', 'sepo-lms'); ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($recent !== []): ?>
                <h2><?php esc_html_e('Latest enrolments', 'sepo-lms'); ?></h2>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e('Student', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('Course', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('Total', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('Status', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('Date', 'sepo-lms'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r['name']); ?><br><span class="description"><?php echo esc_html($r['email']); ?></span></td>
                            <td><?php echo esc_html($r['title']); ?></td>
                            <td><?php echo esc_html(Helpers::priceLabel((float)$r['amount'], (string)$r['currency'])); ?></td>
                            <td><span class="slms-st slms-st--<?php echo esc_attr($r['status']); ?>"><?php echo esc_html(Receipt::statusLabel($r['status'])); ?></span></td>
                            <td><?php echo esc_html(Helpers::datetime((string)$r['created'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e('Getting started', 'sepo-lms'); ?></h2>
            <ol class="slms-steps">
                <li><?php esc_html_e('Add schools and teachers (optional).', 'sepo-lms'); ?></li>
                <li><?php esc_html_e('Create a course: price, tax, discount, level, teacher and school are in the boxes under the editor.', 'sepo-lms'); ?></li>
                <li><?php esc_html_e('Add lessons and quizzes, choosing the course in their “Course” box. Drag them into order in the course’s Outline box.', 'sepo-lms'); ?></li>
                <li><?php esc_html_e('Build quizzes with the visual question builder: pick a question type, type the question, tick the right answers.', 'sepo-lms'); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: %s: link to the settings page */
                        esc_html__('Set your currency and payment methods in %s.', 'sepo-lms'),
                        '<a href="' . esc_url(admin_url('admin.php?page=sepo-lms-settings')) . '">' . esc_html__('Settings', 'sepo-lms') . '</a>'
                    );
                    ?>
                </li>
                <li>
                    <?php
                    $mine = (int)\SepoLms\Settings::get('my_courses_page');
                    printf(
                        /* translators: 1: shortcode, 2: link to the page */
                        esc_html__('Learners see their courses, grades and receipts on the page holding %1$s %2$s.', 'sepo-lms'),
                        '<code>[sepo_lms_my_courses]</code>',
                        $mine > 0 ? '(<a href="' . esc_url((string)get_permalink($mine)) . '">' . esc_html(get_the_title($mine)) . '</a>)' : ''
                    );
                    ?>
                </li>
            </ol>
        </div>
        <?php
    }
}
