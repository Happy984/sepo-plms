<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Course;
use SepoLms\Enrollments;
use SepoLms\Helpers;
use SepoLms\PostTypes;
use SepoLms\Quiz;
use WP_Query;

/**
 * List-table columns and the course filter on the lesson and quiz lists.
 */
final class Columns
{
    public static function init(): void
    {
        add_filter('manage_' . PostTypes::COURSE . '_posts_columns', [self::class, 'courseCols']);
        add_action('manage_' . PostTypes::COURSE . '_posts_custom_column', [self::class, 'courseCell'], 10, 2);

        foreach ([PostTypes::LESSON, PostTypes::QUIZ] as $t) {
            add_filter('manage_' . $t . '_posts_columns', [self::class, 'itemCols']);
            add_action('manage_' . $t . '_posts_custom_column', [self::class, 'itemCell'], 10, 2);
            add_filter('manage_edit-' . $t . '_sortable_columns', [self::class, 'itemSortable']);
        }
        add_action('restrict_manage_posts', [self::class, 'courseFilter']);
        add_action('pre_get_posts', [self::class, 'applyFilter']);
    }

    /** @param array<string,string> $cols */
    public static function courseCols(array $cols): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            $out[$k] = $v;
            if ($k === 'title') {
                $out['slms_price'] = __('Price', 'sepo-lms');
                $out['slms_level'] = __('Level', 'sepo-lms');
                $out['slms_items'] = __('Content', 'sepo-lms');
                $out['slms_enrolled'] = __('Enrolled', 'sepo-lms');
            }
        }
        unset($out['author']);
        return $out;
    }

    public static function courseCell(string $col, int $id): void
    {
        $c = Course::get($id);
        if ($c === null) {
            return;
        }
        switch ($col) {
            case 'slms_price':
                echo esc_html(Helpers::priceLabel($c['total'], $c['currency']));
                if ($c['discount_rate'] > 0) {
                    echo ' <span class="description">−' . esc_html(Helpers::percent($c['discount_rate'])) . '%</span>';
                }
                break;
            case 'slms_level':
                echo esc_html($c['level'] !== '' ? $c['level'] : '—');
                break;
            case 'slms_items':
                $items = Course::items($id, true);
                $q = count(array_filter($items, static fn($p) => $p->post_type === PostTypes::QUIZ));
                /* translators: 1: lessons, 2: quizzes */
                echo esc_html(sprintf(__('%1$d lessons · %2$d quizzes', 'sepo-lms'), count($items) - $q, $q));
                break;
            case 'slms_enrolled':
                $n = Enrollments::count($id);
                if (current_user_can(\SepoLms\Activator::CAP) && $n > 0) {
                    echo '<a href="' . esc_url(admin_url('admin.php?page=sepo-lms-enrollments&course=' . $id)) . '">' . (int)$n . '</a>';
                } else {
                    echo (int)$n;
                }
                break;
        }
    }

    /** @param array<string,string> $cols */
    public static function itemCols(array $cols): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            $out[$k] = $v;
            if ($k === 'title') {
                $out['slms_course'] = __('Course', 'sepo-lms');
                $out['slms_order'] = __('Order', 'sepo-lms');
                $out['slms_opens'] = __('Opens', 'sepo-lms');
                if (($_GET['post_type'] ?? '') === PostTypes::QUIZ) { // phpcs:ignore WordPress.Security.NonceVerification
                    $out['slms_questions'] = __('Questions', 'sepo-lms');
                }
            }
        }
        unset($out['author']);
        return $out;
    }

    public static function itemCell(string $col, int $id): void
    {
        switch ($col) {
            case 'slms_course':
                $cid = PostTypes::courseOf($id);
                if ($cid > 0 && get_post($cid)) {
                    echo '<a href="' . esc_url(add_query_arg(['slms_course' => $cid])) . '">' . esc_html(get_the_title($cid)) . '</a>';
                } else {
                    echo '—';
                }
                break;
            case 'slms_order':
                echo (int)get_post_field('menu_order', $id);
                break;
            case 'slms_opens':
                $d = (string)get_post_meta($id, '_slms_available_from', true);
                echo esc_html($d !== '' ? Helpers::date($d) : '—');
                break;
            case 'slms_questions':
                $qs = Quiz::questions($id);
                $pts = array_sum(array_map(static fn($q) => (float)$q['points'], $qs));
                /* translators: 1: questions, 2: points */
                echo esc_html(sprintf(__('%1$d · %2$s pts', 'sepo-lms'), count($qs), Helpers::percent($pts)));
                break;
        }
    }

    /** @param array<string,string> $cols */
    public static function itemSortable(array $cols): array
    {
        $cols['slms_order'] = 'menu_order';
        return $cols;
    }

    public static function courseFilter(string $postType): void
    {
        if (!in_array($postType, [PostTypes::LESSON, PostTypes::QUIZ], true)) {
            return;
        }
        $cur = isset($_GET['slms_course']) ? absint($_GET['slms_course']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        echo '<label class="screen-reader-text" for="slms-course-filter">' . esc_html__('Filter by course', 'sepo-lms') . '</label>'
            . '<select name="slms_course" id="slms-course-filter"><option value="">' . esc_html__('All courses', 'sepo-lms') . '</option>';
        foreach (get_posts(['post_type' => PostTypes::COURSE, 'post_status' => 'any', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']) as $c) {
            echo '<option value="' . (int)$c->ID . '" ' . selected($cur, $c->ID, false) . '>' . esc_html(get_the_title($c)) . '</option>';
        }
        echo '</select>';
    }

    public static function applyFilter(WP_Query $q): void
    {
        if (!is_admin() || !$q->is_main_query()) {
            return;
        }
        $type = $q->get('post_type');
        if (!in_array($type, [PostTypes::LESSON, PostTypes::QUIZ], true)) {
            return;
        }
        $cid = isset($_GET['slms_course']) ? absint($_GET['slms_course']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        if ($cid > 0) {
            $q->set('meta_query', [['key' => '_slms_course', 'value' => (string)$cid]]); // phpcs:ignore WordPress.DB.SlowDBQuery
            if (!isset($_GET['orderby'])) { // phpcs:ignore WordPress.Security.NonceVerification
                $q->set('orderby', 'menu_order');
                $q->set('order', 'ASC');
            }
        }
    }
}
