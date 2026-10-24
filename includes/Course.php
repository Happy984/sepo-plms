<?php

declare(strict_types=1);

namespace SepoLms;

use WP_Post;

/**
 * Reads a course and works out its price.
 *
 * Pricing is always computed here, on the server, from the course's own
 * settings. Whatever a browser sends about prices is ignored.
 *
 *   discount = base × discount rate
 *   unit     = base − discount
 *   tax      = unit × tax rate
 *   total    = unit + tax           (this is what the learner pays)
 */
final class Course
{
    /** @var array<int,array<string,mixed>> */
    private static array $cache = [];

    /** @return array<string,mixed>|null */
    public static function get(int|WP_Post $post): ?array
    {
        $post = get_post($post);
        if (!$post instanceof WP_Post || $post->post_type !== PostTypes::COURSE) {
            return null;
        }
        if (isset(self::$cache[$post->ID])) {
            return self::$cache[$post->ID];
        }

        $m = static fn(string $k): string => (string)get_post_meta($post->ID, '_slms_' . $k, true);

        $price = max(0.0, round((float)$m('price'), 2));
        $discountRate = min(100.0, max(0.0, (float)$m('discount_rate')));
        $taxRate = min(100.0, max(0.0, (float)$m('tax_rate')));
        $currency = strtoupper($m('currency'));
        if (strlen($currency) !== 3) {
            $currency = (string)Settings::get('currency');
        }

        $discount = $discountRate > 0 ? round($price * $discountRate / 100, 2) : 0.0;
        $unit = round($price - $discount, 2);
        $tax = $taxRate > 0 ? round($unit * $taxRate / 100, 2) : 0.0;
        $total = round($unit + $tax, 2);

        $thumb = get_the_post_thumbnail_url($post, 'large');

        return self::$cache[$post->ID] = [
            'id'             => $post->ID,
            'title'          => get_the_title($post),
            'url'            => (string)get_permalink($post),
            'summary'        => has_excerpt($post) ? (string)get_the_excerpt($post) : '',
            'image'          => $thumb !== false ? (string)$thumb : '',
            'level'          => $m('level'),
            'start'          => $m('start_date'),
            'end'            => $m('end_date'),
            'currency'       => $currency,
            'price'          => $price,
            'discount_rate'  => $discountRate,
            'discount_label' => $m('discount_label') !== '' ? $m('discount_label') : __('Discount', 'sepo-lms'),
            'discount'       => $discount,
            'unit'           => $unit,
            'tax_rate'       => $taxRate,
            'tax_type'       => $m('tax_type') !== '' ? $m('tax_type') : 'VAT',
            'tax_country'    => $m('tax_country'),
            'tax'            => $tax,
            'total'          => $total,
            'free'           => $total <= 0,
            'show_original'  => $m('hide_original_price') !== '1',
            'sequential'     => $m('sequential') === '1',
            'objectives'     => self::lines($m('objectives')),
            'teacher_id'     => (int)$m('teacher'),
            'school_id'      => (int)$m('school'),
            'teacher_bio'    => $m('teacher_bio'),
            'methods'        => array_values(array_filter(array_map('strval', (array)get_post_meta($post->ID, '_slms_methods', true)))),
            'related'        => array_values(array_filter(array_map('intval', (array)get_post_meta($post->ID, '_slms_related', true)))),
            'categories'     => wp_get_post_terms($post->ID, PostTypes::CATEGORY, ['fields' => 'names']),
        ];
    }

    public static function flush(?int $id = null): void
    {
        if ($id === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$id]);
        }
    }

    /** @return list<string> */
    public static function lines(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), static fn($l) => $l !== ''));
    }

    /**
     * Payment methods a learner can use for this course: the site's enabled
     * methods, narrowed to the course's own list when it has one, and with
     * any gateway that has no credentials left out.
     *
     * @param array<string,mixed> $course
     * @return array<string,array<string,mixed>>
     */
    public static function methods(array $course): array
    {
        $out = [];
        foreach (Payments::available() as $id => $m) {
            if ($course['methods'] === [] || in_array($id, $course['methods'], true)) {
                $out[$id] = $m;
            }
        }
        return $out;
    }

    /**
     * The course outline: published lessons and quizzes that belong to the
     * course, in menu order.
     *
     * @return list<WP_Post>
     */
    public static function items(int $courseId, bool $includeDrafts = false): array
    {
        if ($courseId <= 0) {
            return [];
        }
        $posts = get_posts([
            'post_type'        => [PostTypes::LESSON, PostTypes::QUIZ],
            'post_status'      => $includeDrafts ? ['publish', 'draft', 'pending', 'future', 'private'] : 'publish',
            'posts_per_page'   => 500,
            'meta_key'         => '_slms_course', // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value'       => (string)$courseId, // phpcs:ignore WordPress.DB.SlowDBQuery
            'orderby'          => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'suppress_filters' => false,
        ]);
        return array_values(array_filter($posts, static fn($p) => $p instanceof WP_Post));
    }

    /** @return array<string,mixed> A small card for grids and lists. */
    public static function card(int|WP_Post $post): array
    {
        $c = self::get($post);
        if ($c === null) {
            return [];
        }
        $school = $c['school_id'] > 0 ? get_post($c['school_id']) : null;
        $teacher = $c['teacher_id'] > 0 ? get_post($c['teacher_id']) : null;
        return [
            'id'       => $c['id'],
            'title'    => $c['title'],
            'url'      => $c['url'],
            'image'    => $c['image'],
            'summary'  => $c['summary'],
            'level'    => $c['level'],
            'free'     => $c['free'],
            'price'    => Helpers::money($c['unit'], $c['currency']),
            'original' => $c['discount_rate'] > 0 && $c['show_original'] ? Helpers::money($c['price'], $c['currency']) : '',
            'discount' => $c['discount_rate'],
            'school'   => $school instanceof WP_Post && $school->post_status === 'publish' ? get_the_title($school) : '',
            'teacher'  => $teacher instanceof WP_Post && $teacher->post_status === 'publish' ? get_the_title($teacher) : '',
            'search'   => strtolower(implode(' ', array_merge([$c['title'], $c['summary'], $c['level']], $c['categories']))),
        ];
    }
}
