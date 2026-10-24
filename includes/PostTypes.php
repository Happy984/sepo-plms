<?php

declare(strict_types=1);

namespace SepoLms;

use WP_Post;
use WP_REST_Response;

/**
 * Post types: courses, lessons, quizzes, schools and teachers, plus the
 * course category taxonomy.
 *
 * Lessons and quizzes belong to a course through the _slms_course meta key.
 * Their place in the course outline is their menu_order.
 */
final class PostTypes
{
    public const COURSE   = 'sepo_course';
    public const LESSON   = 'sepo_lesson';
    public const QUIZ     = 'sepo_quiz';
    public const SCHOOL   = 'sepo_school';
    public const TEACHER  = 'sepo_teacher';
    public const CATEGORY = 'sepo_course_category';

    public static function init(): void
    {
        add_action('init', [self::class, 'register'], 5);
        add_filter('rest_prepare_' . self::LESSON, [self::class, 'guardRest'], 10, 3);
        add_filter('rest_prepare_' . self::QUIZ, [self::class, 'guardRest'], 10, 3);
        add_action('before_delete_post', [self::class, 'onDelete']);
    }

    public static function register(): void
    {
        $menu = 'sepo-lms';

        register_post_type(self::COURSE, [
            'labels' => self::labels(__('Course', 'sepo-lms'), __('Courses', 'sepo-lms')) + self::imageLabels(__('Course image', 'sepo-lms')),
            'public'       => true,
            'show_in_menu' => $menu,
            'show_in_rest' => true,
            'has_archive'  => Settings::base('courses'),
            'rewrite'      => ['slug' => Settings::base('courses'), 'with_front' => false],
            'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author'],
            'menu_icon'    => 'dashicons-welcome-learn-more',
        ]);

        register_post_type(self::LESSON, [
            'labels' => self::labels(__('Lesson', 'sepo-lms'), __('Lessons', 'sepo-lms')),
            'public'              => true,
            'exclude_from_search' => true,
            'show_in_menu'        => $menu,
            'show_in_rest'        => true,
            'has_archive'         => false,
            'rewrite'             => ['slug' => Settings::base('lessons'), 'with_front' => false, 'feeds' => false],
            'supports'            => ['title', 'editor', 'excerpt', 'page-attributes', 'revisions', 'author'],
        ]);

        register_post_type(self::QUIZ, [
            'labels' => self::labels(__('Quiz', 'sepo-lms'), __('Quizzes', 'sepo-lms')),
            'public'              => true,
            'exclude_from_search' => true,
            'show_in_menu'        => $menu,
            'show_in_rest'        => true,
            'has_archive'         => false,
            'rewrite'             => ['slug' => Settings::base('quizzes'), 'with_front' => false, 'feeds' => false],
            'supports'            => ['title', 'editor', 'excerpt', 'page-attributes', 'revisions', 'author'],
        ]);

        register_post_type(self::SCHOOL, [
            'labels' => self::labels(__('School', 'sepo-lms'), __('Schools', 'sepo-lms')) + self::imageLabels(__('Logo', 'sepo-lms')),
            'public'       => true,
            'show_in_menu' => $menu,
            'show_in_rest' => true,
            'has_archive'  => Settings::base('schools'),
            'rewrite'      => ['slug' => Settings::base('schools'), 'with_front' => false],
            'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
        ]);

        register_post_type(self::TEACHER, [
            'labels' => self::labels(__('Teacher', 'sepo-lms'), __('Teachers', 'sepo-lms')) + self::imageLabels(__('Photo', 'sepo-lms')),
            'public'       => true,
            'show_in_menu' => $menu,
            'show_in_rest' => true,
            'has_archive'  => Settings::base('teachers'),
            'rewrite'      => ['slug' => Settings::base('teachers'), 'with_front' => false],
            'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
        ]);

        register_taxonomy(self::CATEGORY, [self::COURSE], [
            'labels' => [
                'name'          => __('Course categories', 'sepo-lms'),
                'singular_name' => __('Course category', 'sepo-lms'),
                'menu_name'     => __('Categories', 'sepo-lms'),
                'all_items'     => __('All categories', 'sepo-lms'),
                'edit_item'     => __('Edit category', 'sepo-lms'),
                'add_new_item'  => __('Add new category', 'sepo-lms'),
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => Settings::base('courses') . '/category', 'with_front' => false],
        ]);
    }

    /** @return array<string,string> */
    private static function imageLabels(string $name): array
    {
        return [
            'featured_image'        => $name,
            /* translators: %s: image name, e.g. Logo */
            'set_featured_image'    => sprintf(__('Set %s', 'sepo-lms'), strtolower($name)),
            /* translators: %s: image name */
            'remove_featured_image' => sprintf(__('Remove %s', 'sepo-lms'), strtolower($name)),
            /* translators: %s: image name */
            'use_featured_image'    => sprintf(__('Use as %s', 'sepo-lms'), strtolower($name)),
        ];
    }

    /** @return array<string,string> */
    private static function labels(string $one, string $many): array
    {
        return [
            'name'               => $many,
            'singular_name'      => $one,
            'menu_name'          => $many,
            'all_items'          => $many,
            /* translators: %s: item type, e.g. Course */
            'add_new_item'       => sprintf(__('Add new %s', 'sepo-lms'), strtolower($one)),
            'add_new'            => __('Add new', 'sepo-lms'),
            /* translators: %s: item type */
            'edit_item'          => sprintf(__('Edit %s', 'sepo-lms'), strtolower($one)),
            /* translators: %s: item type */
            'new_item'           => sprintf(__('New %s', 'sepo-lms'), strtolower($one)),
            /* translators: %s: item type */
            'view_item'          => sprintf(__('View %s', 'sepo-lms'), strtolower($one)),
            /* translators: %s: item type, plural */
            'search_items'       => sprintf(__('Search %s', 'sepo-lms'), strtolower($many)),
            /* translators: %s: item type, plural */
            'not_found'          => sprintf(__('No %s found', 'sepo-lms'), strtolower($many)),
            /* translators: %s: item type, plural */
            'not_found_in_trash' => sprintf(__('No %s in the bin', 'sepo-lms'), strtolower($many)),
        ];
    }

    /**
     * The block editor reads lessons and quizzes over REST, so the post types
     * have to be there — but a paid lesson must not be readable through
     * /wp/v2/sepo_lesson by someone who has not paid for it. Anyone who
     * cannot edit the post gets the title and link only.
     */
    public static function guardRest(WP_REST_Response $response, WP_Post $post, \WP_REST_Request $request): WP_REST_Response
    {
        if (current_user_can('edit_post', $post->ID)) {
            return $response;
        }
        $data = $response->get_data();
        if (is_array($data)) {
            if (isset($data['content'])) {
                $data['content'] = ['rendered' => '', 'protected' => true];
            }
            if (isset($data['excerpt'])) {
                $data['excerpt'] = ['rendered' => '', 'protected' => true];
            }
            $response->set_data($data);
        }
        return $response;
    }

    /** Course deleted: its enrolments, progress, reviews and quiz results go with it. */
    public static function onDelete(int $postId): void
    {
        $type = get_post_type($postId);
        if ($type === self::COURSE) {
            Enrollments::deleteForCourse($postId);
            Progress::deleteForCourse($postId);
            Reviews::deleteForCourse($postId);
        } elseif ($type === self::QUIZ) {
            QuizResults::deleteForQuiz($postId);
            Progress::deleteForItem($postId);
        } elseif ($type === self::LESSON) {
            Progress::deleteForItem($postId);
        }
    }

    public static function courseOf(int $itemId): int
    {
        return (int)get_post_meta($itemId, '_slms_course', true);
    }
}
