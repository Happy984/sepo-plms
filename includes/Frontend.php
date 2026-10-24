<?php

declare(strict_types=1);

namespace SepoLms;

use WP_Post;
use WP_Query;

/**
 * Front end: templates, assets, shortcodes and the view renderers.
 *
 * Every view is a PHP template in templates/parts. A theme can override any
 * of them by copying it to your-theme/sepo-lms/ (keeping the parts/ folder).
 */
final class Frontend
{
    public static function init(): void
    {
        add_filter('template_include', [self::class, 'templateInclude'], 50);
        add_action('pre_get_posts', [self::class, 'archiveQuery']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
        add_action('template_redirect', [self::class, 'blockEmbeds']);
        add_filter('body_class', [self::class, 'bodyClass']);

        add_shortcode('sepo_lms_courses', [self::class, 'shortcodeCourses']);
        add_shortcode('sepo_lms_schools', [self::class, 'shortcodeSchools']);
        add_shortcode('sepo_lms_teachers', [self::class, 'shortcodeTeachers']);
        add_shortcode('sepo_lms_my_courses', [self::class, 'shortcodeMyCourses']);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [PostTypes::COURSE, PostTypes::LESSON, PostTypes::QUIZ, PostTypes::SCHOOL, PostTypes::TEACHER];
    }

    public static function isLmsContext(): bool
    {
        return is_singular(self::types())
            || is_post_type_archive([PostTypes::COURSE, PostTypes::SCHOOL, PostTypes::TEACHER])
            || is_tax(PostTypes::CATEGORY);
    }

    public static function templateInclude(string $template): string
    {
        if (is_singular(self::types())) {
            return self::locate('single.php');
        }
        if (is_post_type_archive([PostTypes::COURSE, PostTypes::SCHOOL, PostTypes::TEACHER]) || is_tax(PostTypes::CATEGORY)) {
            return self::locate('archive.php');
        }
        return $template;
    }

    public static function locate(string $name): string
    {
        $theme = locate_template(['sepo-lms/' . $name]);
        return $theme !== '' ? $theme : SLMS_DIR . 'templates/' . $name;
    }

    /** Indexes do their own search and paging in the browser, so archives load every item. */
    public static function archiveQuery(WP_Query $q): void
    {
        if (is_admin() || !$q->is_main_query()) {
            return;
        }
        if ($q->is_post_type_archive([PostTypes::COURSE, PostTypes::SCHOOL, PostTypes::TEACHER]) || $q->is_tax(PostTypes::CATEGORY)) {
            $q->set('posts_per_page', 500);
            $q->set('no_found_rows', true);
            $q->set('orderby', ['menu_order' => 'ASC', 'title' => 'ASC']);
        }
    }

    /** Lessons and quizzes are never embedded on other sites. */
    public static function blockEmbeds(): void
    {
        if (is_embed() && is_singular([PostTypes::LESSON, PostTypes::QUIZ])) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
        }
    }

    /** @param list<string> $classes */
    public static function bodyClass(array $classes): array
    {
        if (self::isLmsContext()) {
            $classes[] = 'sepo-lms';
        }
        return $classes;
    }

    // ------------------------------------------------------------- layout

    /** get_header() for classic themes; the header template part for block themes. */
    public static function header(): void
    {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $header = do_blocks('<!-- wp:template-part {"slug":"header","tagName":"header"} /-->');
            ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        get_header();
    }

    public static function footer(): void
    {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            echo do_blocks('<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->'); // phpcs:ignore WordPress.Security.EscapeOutput
            echo "</div>\n";
            wp_footer();
            echo "\n</body>\n</html>\n";
            return;
        }
        get_footer();
    }

    // ------------------------------------------------------------- assets

    public static function registerAssets(): void
    {
        wp_register_style('sepo-lms', SLMS_URL . 'assets/css/lms.css', [], SLMS_VERSION);
        wp_add_inline_style('sepo-lms', ':root{--slms-accent:' . esc_attr((string)Settings::get('accent')) . ';}');

        wp_register_script('sepo-lms', SLMS_URL . 'assets/js/lms.js', [], SLMS_VERSION, true);
        wp_localize_script('sepo-lms', 'SepoLms', [
            'api'   => esc_url_raw(rest_url(Rest::NS)),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n'  => [
                'failed'        => __('That didn’t work. Try again.', 'sepo-lms'),
                'fullName'      => __('Full name', 'sepo-lms'),
                'email'         => __('Email', 'sepo-lms'),
                'nationalId'    => __('National ID', 'sepo-lms'),
                'gender'        => __('Gender', 'sepo-lms'),
                'female'        => __('Female', 'sepo-lms'),
                'male'          => __('Male', 'sepo-lms'),
                'location'      => __('Location', 'sepo-lms'),
                'cityCountry'   => __('City, country', 'sepo-lms'),
                'dob'           => __('Date of birth', 'sepo-lms'),
                'method'        => __('Payment method', 'sepo-lms'),
                'phone'         => __('Mobile money number', 'sepo-lms'),
                'required'      => __('Name and email are required.', 'sepo-lms'),
                'phoneRequired' => __('A phone number is required for this payment method.', 'sepo-lms'),
                'enroll'        => __('Enrol', 'sepo-lms'),
                'enrollPay'     => __('Enrol & pay', 'sepo-lms'),
                'cancel'        => __('Cancel', 'sepo-lms'),
                'close'         => __('Close', 'sepo-lms'),
                'done'          => __('Done', 'sepo-lms'),
                'enrolled'      => __('You are enrolled', 'sepo-lms'),
                'enrolledPaid'  => __('Enrolled and paid', 'sepo-lms'),
                'waiting'       => __('Waiting for payment…', 'sepo-lms'),
                'paidNow'       => __('Payment received. The course is open.', 'sepo-lms'),
                'payFailed'     => __('The payment did not go through. You can try again.', 'sepo-lms'),
                'stillWaiting'  => __('We have not heard back yet. If you approved the payment, this page will update shortly; you can also check My courses later.', 'sepo-lms'),
                'redirecting'   => __('Taking you to the secure checkout…', 'sepo-lms'),
                'clickHere'     => __('Continue to payment', 'sepo-lms'),
                'payNow'        => __('Pay now', 'sepo-lms'),
                'receipt'       => __('View receipt', 'sepo-lms'),
                'posting'       => __('Posting…', 'sepo-lms'),
                'deleteTitle'   => __('Delete review', 'sepo-lms'),
                'deleteMsg'     => __('Delete this review? This cannot be undone.', 'sepo-lms'),
                'delete'        => __('Delete', 'sepo-lms'),
                'answerAll'     => __('Please answer all required questions before submitting.', 'sepo-lms'),
                'confirmSubmit' => __('Submit your answers?', 'sepo-lms'),
                'submitting'    => __('Submitting…', 'sepo-lms'),
                'submit'        => __('Submit quiz', 'sepo-lms'),
                'passed'        => __('Passed', 'sepo-lms'),
                'notPassed'     => __('Not passed', 'sepo-lms'),
                'correct'       => __('Correct', 'sepo-lms'),
                'incorrect'     => __('Incorrect', 'sepo-lms'),
                'unanswered'    => __('Unanswered', 'sepo-lms'),
                'notGraded'     => __('Not graded', 'sepo-lms'),
                'total'         => __('Total', 'sepo-lms'),
                'yourAnswer'    => __('Your answer', 'sepo-lms'),
                'correctAnswer' => __('Correct answer', 'sepo-lms'),
                'explanation'   => __('Explanation', 'sepo-lms'),
                'notAnswered'   => __('Not answered', 'sepo-lms'),
                'review'        => __('Review your answers', 'sepo-lms'),
                'submitted'     => __('Your answers were submitted. Your result will appear under My courses.', 'sepo-lms'),
                'retake'        => __('Take it again', 'sepo-lms'),
                'backToCourse'  => __('Back to the course', 'sepo-lms'),
                'attempt'       => __('Attempt %s', 'sepo-lms'),
                'answered'      => __('Answered: %s', 'sepo-lms'),
                'ofCorrect'     => __('%1$s / %2$s correct', 'sepo-lms'),
                'page'          => __('Page %1$s of %2$s', 'sepo-lms'),
                'showing'       => __('Showing %1$s–%2$s of %3$s', 'sepo-lms'),
                'noMatch'       => __('Nothing matches your search.', 'sepo-lms'),
                'prev'          => __('Previous page', 'sepo-lms'),
                'next'          => __('Next page', 'sepo-lms'),
                'timeUp'        => __('Time is up. Submitting your answers…', 'sepo-lms'),
                'leave'         => __('You have unsaved answers.', 'sepo-lms'),
            ],
        ]);

        $post = get_post();
        $codes = ['sepo_lms_courses', 'sepo_lms_schools', 'sepo_lms_teachers', 'sepo_lms_my_courses'];
        $hasCode = false;
        if ($post instanceof WP_Post) {
            foreach ($codes as $c) {
                if (has_shortcode((string)$post->post_content, $c)) {
                    $hasCode = true;
                    break;
                }
            }
        }
        if (self::isLmsContext() || $hasCode) {
            self::enqueue();
        }
    }

    public static function enqueue(): void
    {
        wp_enqueue_style('sepo-lms');
        wp_enqueue_script('sepo-lms');
    }

    /**
     * Render a template part to a string.
     *
     * @param array<string,mixed> $vars
     */
    public static function part(string $name, array $vars): string
    {
        $file = self::locate('parts/' . $name . '.php');
        ob_start();
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract
            require $__file;
        })($file, $vars);
        return (string)ob_get_clean();
    }

    // ---------------------------------------------------------- renderers

    public static function renderSingle(WP_Post $post): string
    {
        return match ($post->post_type) {
            PostTypes::COURSE  => self::renderCourse($post),
            PostTypes::LESSON  => self::renderLesson($post),
            PostTypes::QUIZ    => self::renderQuiz($post),
            PostTypes::SCHOOL  => self::renderSchool($post),
            PostTypes::TEACHER => self::renderTeacher($post),
            default            => '',
        };
    }

    public static function renderArchive(): string
    {
        if (is_post_type_archive(PostTypes::SCHOOL)) {
            return self::schoolIndex(self::mainPosts(), (string)post_type_archive_title('', false), '');
        }
        if (is_post_type_archive(PostTypes::TEACHER)) {
            return self::teacherIndex(self::mainPosts(), (string)post_type_archive_title('', false), '');
        }
        $title = is_tax() ? single_term_title('', false) : post_type_archive_title('', false);
        $intro = is_tax() ? term_description() : '';
        return self::courseIndex(self::mainPosts(), (string)$title, (string)$intro);
    }

    /** @return list<WP_Post> */
    private static function mainPosts(): array
    {
        global $wp_query;
        return array_values(array_filter((array)$wp_query->posts, static fn($p) => $p instanceof WP_Post));
    }

    /** @param list<WP_Post> $posts */
    public static function courseIndex(array $posts, string $title, string $intro, int $perPage = 0): string
    {
        $cards = array_values(array_filter(array_map([Course::class, 'card'], $posts)));
        $levels = array_values(array_unique(array_filter(array_map(static fn($c) => (string)$c['level'], $cards))));
        sort($levels);
        return self::part('course-index', [
            'title'   => $title,
            'intro'   => $intro,
            'cards'   => $cards,
            'levels'  => $levels,
            'perPage' => $perPage > 0 ? $perPage : (int)Settings::get('per_page'),
        ]);
    }

    /** @param list<WP_Post> $posts */
    public static function schoolIndex(array $posts, string $title, string $intro, int $perPage = 0): string
    {
        $cards = [];
        foreach ($posts as $p) {
            $cards[] = self::schoolData($p);
        }
        return self::part('school-index', [
            'title'   => $title,
            'intro'   => $intro,
            'cards'   => $cards,
            'perPage' => $perPage > 0 ? $perPage : (int)Settings::get('per_page'),
        ]);
    }

    /** @param list<WP_Post> $posts */
    public static function teacherIndex(array $posts, string $title, string $intro, int $perPage = 0): string
    {
        $cards = [];
        foreach ($posts as $p) {
            $cards[] = self::teacherData($p);
        }
        return self::part('teacher-index', [
            'title'   => $title,
            'intro'   => $intro,
            'cards'   => $cards,
            'perPage' => $perPage > 0 ? $perPage : (int)Settings::get('per_page'),
        ]);
    }

    public static function renderCourse(WP_Post $post): string
    {
        $course = Course::get($post);
        if ($course === null) {
            return '';
        }
        $uid = get_current_user_id();
        $enrollment = $uid > 0 ? Enrollments::forCourseUser($post->ID, $uid) : null;
        $outline = Access::outline($post->ID, $uid);
        $canEdit = Access::canEditCourse($post->ID);

        $doneCount = count(array_filter($outline, static fn($r) => $r['done']));
        $reviewData = [];
        foreach ($outline as $row) {
            if ($row['type'] === 'quiz' && $row['result'] !== null) {
                $s = Quiz::settings($row['id']);
                $more = $s['allow_retake'] && ($s['max_attempts'] === 0 || $row['result']['attempt'] < $s['max_attempts']);
                $reviewData[$row['id']] = [
                    'title'     => $row['title'],
                    'score'     => $row['result']['score'],
                    'passed'    => $row['result']['passed'],
                    'attempt'   => $row['result']['attempt'],
                    'total'     => $row['result']['total_questions'],
                    'answered'  => $row['result']['answered'],
                    'correct'   => $row['result']['correct'],
                    'retake'    => $more ? $row['url'] : '',
                    // Correct answers stay hidden while another attempt is possible.
                    'questions' => $s['review_answers'] ? Quiz::review($row['id'], $row['result'], !$more) : [],
                ];
            }
        }

        $methods = [];
        foreach (Course::methods($course) as $id => $m) {
            $methods[] = ['id' => $id, 'label' => (string)$m['label'], 'phone' => Payments::needsPhone($m), 'type' => (string)$m['type']];
        }

        $teacher = $course['teacher_id'] > 0 ? get_post($course['teacher_id']) : null;
        $school = $course['school_id'] > 0 ? get_post($course['school_id']) : null;
        $related = [];
        foreach ($course['related'] as $rid) {
            if (get_post_status($rid) === 'publish' && get_post_type($rid) === PostTypes::COURSE) {
                $related[] = Course::card($rid);
            }
        }

        $user = wp_get_current_user();
        $fields = [
            'national_id' => (bool)Settings::get('collect_national_id'),
            'gender'      => (bool)Settings::get('collect_gender'),
            'location'    => (bool)Settings::get('collect_location'),
            'dob'         => (bool)Settings::get('collect_dob'),
        ];

        $payment = isset($_GET['slms_payment']) ? sanitize_key(wp_unslash((string)$_GET['slms_payment'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return self::part('course', [
            'post'        => $post,
            'course'      => $course,
            'uid'         => $uid,
            'enrollment'  => $enrollment,
            'access'      => $canEdit || ($uid > 0 && Enrollments::hasAccess($post->ID, $uid)),
            'canEdit'     => $canEdit,
            'outline'     => $outline,
            'doneCount'   => $doneCount,
            'reviewData'  => $reviewData,
            'methods'     => $methods,
            'count'       => Enrollments::count($post->ID),
            'summary'     => Reviews::summary($post->ID),
            'reviews'     => Reviews::forCourse($post->ID),
            'teacher'     => $teacher instanceof WP_Post && $teacher->post_status === 'publish' ? $teacher : null,
            'school'      => $school instanceof WP_Post && $school->post_status === 'publish' ? $school : null,
            'related'     => $related,
            'body'        => apply_filters('the_content', $post->post_content), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
            'fields'      => $fields,
            'prefill'     => ['name' => $user->display_name ?? '', 'email' => $user->user_email ?? ''],
            'payment'     => $payment,
        ]);
    }

    public static function renderLesson(WP_Post $post): string
    {
        $uid = get_current_user_id();
        $access = Access::item($post, $uid);
        $courseId = PostTypes::courseOf($post->ID);
        $course = $access['course'];

        if (!$access['allowed']) {
            return self::part('locked', ['post' => $post, 'course' => $course, 'reason' => $access['reason'], 'code' => $access['code']]);
        }

        $prev = null;
        $next = null;
        $done = false;
        if ($courseId > 0) {
            $outline = Access::outline($courseId, $uid);
            foreach ($outline as $i => $row) {
                if ($row['id'] === $post->ID) {
                    $prev = $outline[$i - 1] ?? null;
                    $next = $outline[$i + 1] ?? null;
                    $done = $row['done'];
                    break;
                }
            }
        }
        return self::part('lesson', [
            'post'      => $post,
            'course'    => $course,
            'body'      => apply_filters('the_content', $post->post_content), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
            'prev'      => $prev,
            'next'      => $next,
            'done'      => $done,
            'canTrack'  => $uid > 0 && $course !== null,
        ]);
    }

    public static function renderQuiz(WP_Post $post): string
    {
        $uid = get_current_user_id();
        $s = Quiz::settings($post);
        $course = $s['course_id'] > 0 ? Course::get($s['course_id']) : null;
        $courseUrl = $course['url'] ?? '';
        $back = $courseUrl !== '' ? [['label' => __('Back to the course', 'sepo-lms'), 'href' => $courseUrl]] : [];

        $existing = $uid > 0 ? QuizResults::get($post->ID, $uid) : null;

        if ($existing !== null && !$s['allow_retake']) {
            return self::part('quiz-taken', ['post' => $post, 'settings' => $s, 'result' => $existing, 'courseUrl' => $courseUrl,
                'review' => $s['review_answers'] ? Quiz::review($post->ID, $existing, true) : []]);
        }

        if ($uid <= 0 && ($s['require_login'] || ($s['require_enrollment'] && $course !== null))) {
            return self::part('gate', ['title' => __('Sign in required', 'sepo-lms'), 'message' => __('Please sign in to take this quiz.', 'sepo-lms'),
                'actions' => array_merge([['label' => __('Sign in', 'sepo-lms'), 'href' => Helpers::loginUrl((string)get_permalink($post)), 'primary' => true]], $back)]);
        }

        if ($s['require_enrollment'] && $course !== null) {
            $access = Access::item($post, $uid);
            if (!$access['allowed']) {
                return self::part('gate', ['title' => __('Not available yet', 'sepo-lms'), 'message' => $access['reason'],
                    'actions' => $courseUrl !== '' ? [['label' => __('Go to the course', 'sepo-lms'), 'href' => $courseUrl, 'primary' => true]] : []]);
            }
        }

        if ($existing !== null && $s['max_attempts'] > 0 && $existing['attempt'] >= $s['max_attempts']) {
            return self::part('quiz-taken', ['post' => $post, 'settings' => $s, 'result' => $existing, 'courseUrl' => $courseUrl,
                'review' => $s['review_answers'] ? Quiz::review($post->ID, $existing, true) : [], 'maxed' => true]);
        }

        $questions = Quiz::questions($post->ID);
        if ($questions === []) {
            return self::part('gate', ['title' => $s['title'], 'message' => __('This quiz has no questions yet.', 'sepo-lms'), 'actions' => $back]);
        }

        $remaining = 0;
        if ($s['time_limit'] > 0 && $uid > 0) {
            $key = '_slms_qstart_' . $post->ID;
            $started = (int)get_user_meta($uid, $key, true);
            if ($started <= 0 || time() - $started > $s['time_limit'] * 60 + 120) {
                $started = time();
                update_user_meta($uid, $key, $started);
            }
            $remaining = max(0, $s['time_limit'] * 60 - (time() - $started));
        } elseif ($s['time_limit'] > 0) {
            $remaining = $s['time_limit'] * 60;
        }

        return self::part('quiz', [
            'post'      => $post,
            'settings'  => $s,
            'questions' => $questions,
            'pages'     => array_chunk($questions, $s['per_page']),
            'attempt'   => (int)($existing['attempt'] ?? 0) + 1,
            'remaining' => $remaining,
            'courseUrl' => $courseUrl,
            'askEmail'  => $uid <= 0 && $s['collect_email'],
            'intro'     => apply_filters('the_content', $post->post_content), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
        ]);
    }

    /** @return array<string,mixed> */
    public static function schoolData(WP_Post $p): array
    {
        $m = static fn(string $k): string => (string)get_post_meta($p->ID, '_slms_' . $k, true);
        $logo = get_the_post_thumbnail_url($p, 'medium');
        return [
            'id'           => $p->ID,
            'name'         => get_the_title($p),
            'url'          => (string)get_permalink($p),
            'summary'      => has_excerpt($p) ? (string)get_the_excerpt($p) : '',
            'logo'         => $logo !== false ? (string)$logo : '',
            'cover'        => Helpers::image($m('cover'), 'large'),
            'location'     => $m('location'),
            'phone'        => $m('phone'),
            'email'        => $m('email'),
            'website'      => $m('website'),
            'registration' => $m('registration_number'),
            'tax_label'    => $m('tax_label') !== '' ? $m('tax_label') : __('TPIN', 'sepo-lms'),
            'tax_number'   => $m('tax_number'),
            'tax_country'  => $m('tax_country'),
            'accreditations' => Course::lines($m('accreditations')),
        ];
    }

    /** @return array<string,mixed> */
    public static function teacherData(WP_Post $p): array
    {
        $m = static fn(string $k): string => (string)get_post_meta($p->ID, '_slms_' . $k, true);
        $avatar = get_the_post_thumbnail_url($p, 'medium');
        $socials = [];
        foreach (Course::lines($m('socials')) as $line) {
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($url === '' && filter_var($label, FILTER_VALIDATE_URL)) {
                $url = $label;
                $label = (string)wp_parse_url($label, PHP_URL_HOST);
            }
            if ($url !== '') {
                $socials[] = ['label' => $label, 'url' => $url];
            }
        }
        $schoolId = (int)$m('school');
        return [
            'id'       => $p->ID,
            'name'     => get_the_title($p),
            'url'      => (string)get_permalink($p),
            'summary'  => has_excerpt($p) ? (string)get_the_excerpt($p) : '',
            'avatar'   => $avatar !== false ? (string)$avatar : '',
            'cover'    => Helpers::image($m('cover'), 'large'),
            'role'     => $m('role'),
            'email'    => $m('email'),
            'phone'    => $m('phone'),
            'website'  => $m('website'),
            'subjects' => Course::lines($m('subjects')),
            'socials'  => $socials,
            'school'   => $schoolId > 0 && get_post_status($schoolId) === 'publish' ? ['name' => get_the_title($schoolId), 'url' => (string)get_permalink($schoolId)] : null,
        ];
    }

    /** @return list<WP_Post> published courses whose meta key points at $id */
    private static function coursesBy(string $key, int $id): array
    {
        return get_posts([
            'post_type'      => PostTypes::COURSE,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_key'       => '_slms_' . $key, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value'     => (string)$id, // phpcs:ignore WordPress.DB.SlowDBQuery
            'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ]);
    }

    public static function renderSchool(WP_Post $post): string
    {
        $teachers = get_posts([
            'post_type'      => PostTypes::TEACHER,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_key'       => '_slms_school', // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value'     => (string)$post->ID, // phpcs:ignore WordPress.DB.SlowDBQuery
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        return self::part('school', [
            'post'     => $post,
            'school'   => self::schoolData($post),
            'body'     => apply_filters('the_content', $post->post_content), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
            'courses'  => array_values(array_filter(array_map([Course::class, 'card'], self::coursesBy('school', $post->ID)))),
            'teachers' => array_map([self::class, 'teacherData'], $teachers),
        ]);
    }

    public static function renderTeacher(WP_Post $post): string
    {
        return self::part('teacher', [
            'post'    => $post,
            'teacher' => self::teacherData($post),
            'body'    => apply_filters('the_content', $post->post_content), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
            'courses' => array_values(array_filter(array_map([Course::class, 'card'], self::coursesBy('teacher', $post->ID)))),
        ]);
    }

    // --------------------------------------------------------- shortcodes

    /** @param array<string,string>|string $atts */
    public static function shortcodeCourses($atts): string
    {
        $a = shortcode_atts(['category' => '', 'school' => '', 'teacher' => '', 'level' => '', 'ids' => '', 'limit' => '0', 'per_page' => '0', 'title' => ''], is_array($atts) ? $atts : [], 'sepo_lms_courses');
        $args = [
            'post_type'      => PostTypes::COURSE,
            'post_status'    => 'publish',
            'posts_per_page' => (int)$a['limit'] > 0 ? (int)$a['limit'] : 500,
            'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ];
        if ($a['ids'] !== '') {
            $args['post__in'] = array_map('absint', explode(',', $a['ids']));
            $args['orderby'] = 'post__in';
        }
        if ($a['category'] !== '') {
            $args['tax_query'] = [['taxonomy' => PostTypes::CATEGORY, 'field' => 'slug', 'terms' => array_map('trim', explode(',', $a['category']))]]; // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        $meta = [];
        foreach (['school', 'teacher'] as $k) {
            if ($a[$k] !== '') {
                $meta[] = ['key' => '_slms_' . $k, 'value' => (string)absint($a[$k])];
            }
        }
        if ($a['level'] !== '') {
            $meta[] = ['key' => '_slms_level', 'value' => sanitize_text_field($a['level'])];
        }
        if ($meta !== []) {
            $args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        self::enqueue();
        return self::courseIndex(get_posts($args), (string)$a['title'], '', (int)$a['per_page']);
    }

    /** @param array<string,string>|string $atts */
    public static function shortcodeSchools($atts): string
    {
        $a = shortcode_atts(['limit' => '0', 'per_page' => '0', 'title' => ''], is_array($atts) ? $atts : [], 'sepo_lms_schools');
        self::enqueue();
        $posts = get_posts(['post_type' => PostTypes::SCHOOL, 'post_status' => 'publish', 'posts_per_page' => (int)$a['limit'] > 0 ? (int)$a['limit'] : 500, 'orderby' => 'title', 'order' => 'ASC']);
        return self::schoolIndex($posts, (string)$a['title'], '', (int)$a['per_page']);
    }

    /** @param array<string,string>|string $atts */
    public static function shortcodeTeachers($atts): string
    {
        $a = shortcode_atts(['school' => '', 'limit' => '0', 'per_page' => '0', 'title' => ''], is_array($atts) ? $atts : [], 'sepo_lms_teachers');
        $args = ['post_type' => PostTypes::TEACHER, 'post_status' => 'publish', 'posts_per_page' => (int)$a['limit'] > 0 ? (int)$a['limit'] : 500, 'orderby' => 'title', 'order' => 'ASC'];
        if ($a['school'] !== '') {
            $args['meta_query'] = [['key' => '_slms_school', 'value' => (string)absint($a['school'])]]; // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        self::enqueue();
        return self::teacherIndex(get_posts($args), (string)$a['title'], '', (int)$a['per_page']);
    }

    public static function shortcodeMyCourses(): string
    {
        self::enqueue();
        $uid = get_current_user_id();
        if ($uid <= 0) {
            return self::part('gate-inline', [
                'message' => __('Sign in to see your courses.', 'sepo-lms'),
                'href'    => Helpers::loginUrl(),
                'label'   => __('Sign in', 'sepo-lms'),
            ]);
        }

        $results = QuizResults::forUser($uid);
        $byCourse = [];
        foreach ($results as $r) {
            $byCourse[(int)$r['course_id']][] = $r;
        }

        $rows = [];
        $groups = [];
        foreach (Enrollments::forUser($uid) as $e) {
            $courseId = (int)$e['course_id'];
            $exists = get_post_status($courseId) === 'publish';
            $outline = $exists ? Access::outline($courseId, $uid) : [];
            $done = count(array_filter($outline, static fn($r) => $r['done']));
            $grade = self::finalGrade($byCourse[$courseId] ?? []);

            $gw = (string)$e['gateway'];
            $rows[] = [
                'e'         => $e,
                'url'       => $exists ? (string)get_permalink($courseId) : '',
                'progress'  => $outline !== [] ? (int)round($done / count($outline) * 100) : 0,
                'done'      => $done,
                'items'     => count($outline),
                'grade'     => $grade['grade'],
                'letter'    => $grade['letter'],
                'weighted'  => $grade['weighted'],
                'quizzes'   => $grade['count'],
                'canResume' => $e['status'] === 'pending' && $gw !== '' && $exists,
            ];

            // Every quiz in the course, taken or not, in outline order.
            $items = [];
            foreach ($outline as $o) {
                if ($o['type'] === 'quiz') {
                    $items[] = self::quizRow($o['id'], $o['result'], $o['accessible'], $o['reason']);
                }
            }
            // Results for quizzes no longer in the outline still count.
            foreach ($byCourse[$courseId] ?? [] as $r) {
                if (!in_array($r['quiz_id'], array_column($items, 'id'), true)) {
                    $items[] = self::quizRow($r['quiz_id'], $r, true, '');
                }
            }
            if ($items !== []) {
                $groups[$courseId] = [
                    'id'    => $courseId,
                    'title' => (string)($e['title'] ?: get_the_title($courseId)),
                    'url'   => $exists ? (string)get_permalink($courseId) : '',
                    'grade' => $grade,
                    'items' => $items,
                ];
            }
        }

        // Quizzes taken outside an enrolment: a course the learner is not
        // enrolled in, or a quiz with no course.
        foreach ($byCourse as $courseId => $list) {
            if (isset($groups[$courseId])) {
                continue;
            }
            $items = array_map(static fn($r) => self::quizRow($r['quiz_id'], $r, true, ''), $list);
            $groups[$courseId] = [
                'id'    => $courseId,
                'title' => $courseId > 0 ? get_the_title($courseId) : __('Other quizzes', 'sepo-lms'),
                'url'   => $courseId > 0 && get_post_status($courseId) === 'publish' ? (string)get_permalink($courseId) : '',
                'grade' => self::finalGrade($courseId > 0 ? $list : []),
                'items' => $items,
            ];
        }

        return self::part('my-courses', [
            'rows'    => $rows,
            'stats'   => QuizResults::userStats($uid),
            'groups'  => array_values($groups),
            'taken'   => count($results),
        ]);
    }

    /**
     * A course's final grade from the learner's quiz results: weighted when
     * every quiz has a weight, a plain average of the scores otherwise.
     *
     * @param list<array<string,mixed>> $results
     * @return array{grade:?float,letter:string,weighted:bool,count:int,passed:int}
     */
    public static function finalGrade(array $results): array
    {
        $scores = array_map(static fn($r) => (float)$r['score'], $results);
        $weights = array_map(static fn($r) => (float)$r['weight'], $results);
        $grade = null;
        $weighted = false;
        if ($scores !== []) {
            if (!in_array(0.0, $weights, true) && array_sum($weights) > 0) {
                $sum = 0.0;
                foreach ($scores as $i => $sc) {
                    $sum += $sc * $weights[$i];
                }
                $grade = round($sum / array_sum($weights), 1);
                $weighted = true;
            } else {
                $grade = round(array_sum($scores) / count($scores), 1);
            }
        }
        return [
            'grade'    => $grade,
            'letter'   => $grade !== null ? Helpers::letterGrade($grade) : '',
            'weighted' => $weighted,
            'count'    => count($scores),
            'passed'   => count(array_filter($results, static fn($r) => !empty($r['passed']))),
        ];
    }

    /**
     * @param array<string,mixed>|null $result
     * @return array<string,mixed>
     */
    private static function quizRow(int $quizId, ?array $result, bool $accessible, string $reason): array
    {
        $weight = (float)get_post_meta($quizId, '_slms_weight', true);
        return [
            'id'         => $quizId,
            'title'      => get_the_title($quizId),
            'url'        => (string)get_permalink($quizId),
            'result'     => $result,
            'weight'     => $weight,
            'accessible' => $accessible,
            'reason'     => $reason,
        ];
    }
}
