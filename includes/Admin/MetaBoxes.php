<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\Course;
use SepoLms\Enrollments;
use SepoLms\Helpers;
use SepoLms\PostTypes;
use SepoLms\Settings;
use WP_Post;

/**
 * Edit-screen boxes for courses, lessons, quizzes, schools and teachers.
 * Everything here is plain form fields.
 */
final class MetaBoxes
{
    private const NONCE = 'slms_meta_nonce';

    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'register']);
        add_action('save_post', [self::class, 'save'], 10, 2);
    }

    public static function register(): void
    {
        add_meta_box('slms-pricing', __('Price', 'sepo-lms'), [self::class, 'pricingBox'], PostTypes::COURSE, 'normal', 'high');
        add_meta_box('slms-outline', __('Outline', 'sepo-lms'), [self::class, 'outlineBox'], PostTypes::COURSE, 'normal', 'high');
        add_meta_box('slms-details', __('Course details', 'sepo-lms'), [self::class, 'detailsBox'], PostTypes::COURSE, 'normal', 'default');
        add_meta_box('slms-people', __('Teacher and school', 'sepo-lms'), [self::class, 'peopleBox'], PostTypes::COURSE, 'normal', 'default');
        add_meta_box('slms-methods', __('Payment methods', 'sepo-lms'), [self::class, 'methodsBox'], PostTypes::COURSE, 'side', 'default');
        add_meta_box('slms-related', __('Related courses', 'sepo-lms'), [self::class, 'relatedBox'], PostTypes::COURSE, 'side', 'low');
        add_meta_box('slms-enrolments', __('Enrolments', 'sepo-lms'), [self::class, 'enrolmentsBox'], PostTypes::COURSE, 'side', 'high');

        add_meta_box('slms-course', __('Course', 'sepo-lms'), [self::class, 'courseBox'], [PostTypes::LESSON, PostTypes::QUIZ], 'side', 'high');
        add_meta_box('slms-school', __('School details', 'sepo-lms'), [self::class, 'schoolBox'], PostTypes::SCHOOL, 'normal', 'high');
        add_meta_box('slms-teacher', __('Teacher details', 'sepo-lms'), [self::class, 'teacherBox'], PostTypes::TEACHER, 'normal', 'high');
    }

    private static function m(WP_Post $post, string $key): string
    {
        return (string)get_post_meta($post->ID, '_slms_' . $key, true);
    }

    private static function nonce(): void
    {
        static $done = false;
        if (!$done) {
            wp_nonce_field('slms_save_meta', self::NONCE);
            $done = true;
        }
    }

    private static function text(WP_Post $post, string $key, string $label, string $type = 'text', string $help = '', string $attrs = ''): void
    {
        $id = 'slms_' . $key;
        echo '<p class="slms-f"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<input type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="slms[' . esc_attr($key) . ']" value="' . esc_attr(self::m($post, $key)) . '" class="widefat" ' . $attrs . '>' // phpcs:ignore WordPress.Security.EscapeOutput
            . ($help !== '' ? '<span class="description">' . esc_html($help) . '</span>' : '') . '</p>';
    }

    private static function area(WP_Post $post, string $key, string $label, string $help = '', int $rows = 4): void
    {
        $id = 'slms_' . $key;
        echo '<p class="slms-f"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<textarea id="' . esc_attr($id) . '" name="slms[' . esc_attr($key) . ']" rows="' . (int)$rows . '" class="widefat">' . esc_textarea(self::m($post, $key)) . '</textarea>'
            . ($help !== '' ? '<span class="description">' . esc_html($help) . '</span>' : '') . '</p>';
    }

    private static function check(WP_Post $post, string $key, string $label, bool $default = false): void
    {
        $v = self::m($post, $key);
        $on = $v === '' ? $default : $v === '1';
        echo '<p class="slms-f slms-f--check"><input type="hidden" name="slms[' . esc_attr($key) . ']" value="0">'
            . '<label><input type="checkbox" name="slms[' . esc_attr($key) . ']" value="1" ' . checked($on, true, false) . '> ' . esc_html($label) . '</label></p>';
    }

    /** @param array<int,string> $choices */
    private static function select(WP_Post $post, string $key, string $label, array $choices, string $none): void
    {
        $id = 'slms_' . $key;
        $cur = self::m($post, $key);
        echo '<p class="slms-f"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><select id="' . esc_attr($id) . '" name="slms[' . esc_attr($key) . ']" class="widefat">'
            . '<option value="">' . esc_html($none) . '</option>';
        foreach ($choices as $id2 => $title) {
            echo '<option value="' . (int)$id2 . '" ' . selected($cur, (string)$id2, false) . '>' . esc_html($title) . '</option>';
        }
        echo '</select></p>';
    }

    /** @return array<int,string> */
    private static function choices(string $type): array
    {
        $out = [];
        foreach (get_posts(['post_type' => $type, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']) as $p) {
            $out[$p->ID] = get_the_title($p) . ($p->post_status !== 'publish' ? ' (' . $p->post_status . ')' : '');
        }
        return $out;
    }

    // ---------------------------------------------------------------- course

    public static function pricingBox(WP_Post $post): void
    {
        self::nonce();
        $currency = self::m($post, 'currency');
        $c = Course::get($post) ?? [];
        ?>
        <div class="slms-cols" data-slms-pricing data-default-currency="<?php echo esc_attr((string)Settings::get('currency')); ?>">
            <div>
                <?php self::text($post, 'price', __('Price', 'sepo-lms'), 'number', __('Leave at 0 for a free course.', 'sepo-lms'), 'min="0" step="0.01" data-p="price"'); ?>
                <p class="slms-f"><label for="slms_currency"><?php esc_html_e('Currency', 'sepo-lms'); ?></label>
                    <input type="text" id="slms_currency" name="slms[currency]" value="<?php echo esc_attr($currency); ?>" class="widefat" maxlength="3" placeholder="<?php echo esc_attr((string)Settings::get('currency')); ?>" data-p="currency">
                    <span class="description"><?php esc_html_e('Three-letter code. Empty uses the site currency.', 'sepo-lms'); ?></span></p>
            </div>
            <div>
                <?php self::text($post, 'discount_rate', __('Discount %', 'sepo-lms'), 'number', '', 'min="0" max="100" step="0.01" data-p="discount"'); ?>
                <?php self::text($post, 'discount_label', __('Discount label', 'sepo-lms'), 'text', '', 'placeholder="' . esc_attr__('Discount', 'sepo-lms') . '"'); ?>
                <?php self::check($post, 'hide_original_price', __('Hide the price before discount', 'sepo-lms')); ?>
            </div>
            <div>
                <?php self::text($post, 'tax_rate', __('Tax %', 'sepo-lms'), 'number', __('Added on top of the discounted price.', 'sepo-lms'), 'min="0" max="100" step="0.01" data-p="tax"'); ?>
                <?php self::text($post, 'tax_type', __('Tax name', 'sepo-lms'), 'text', '', 'placeholder="VAT"'); ?>
                <?php self::text($post, 'tax_country', __('Tax country', 'sepo-lms')); ?>
            </div>
        </div>
        <p class="slms-price-preview" data-slms-preview>
            <?php
            if ($c !== [] && $c['price'] > 0) {
                /* translators: 1: base price, 2: after discount, 3: tax, 4: total */
                echo esc_html(sprintf(__('Price %1$s → after discount %2$s → tax %3$s → learner pays %4$s', 'sepo-lms'),
                    Helpers::money($c['price'], $c['currency']), Helpers::money($c['unit'], $c['currency']), Helpers::money($c['tax'], $c['currency']), Helpers::money($c['total'], $c['currency'])));
            }
            ?>
        </p>
        <?php
    }

    public static function detailsBox(WP_Post $post): void
    {
        self::nonce();
        echo '<div class="slms-cols">';
        echo '<div>';
        self::text($post, 'level', __('Level', 'sepo-lms'), 'text', __('For example Beginner, Intermediate, Advanced.', 'sepo-lms'), 'list="slms-levels"');
        echo '<datalist id="slms-levels"><option value="' . esc_attr__('Beginner', 'sepo-lms') . '"><option value="' . esc_attr__('Intermediate', 'sepo-lms') . '"><option value="' . esc_attr__('Advanced', 'sepo-lms') . '"></datalist>';
        self::check($post, 'sequential', __('Lessons open one after another (each needs the one before it done)', 'sepo-lms'));
        echo '</div><div>';
        self::text($post, 'start_date', __('Start date', 'sepo-lms'), 'date');
        self::text($post, 'end_date', __('End date', 'sepo-lms'), 'date');
        echo '</div></div>';
        self::area($post, 'objectives', __('What learners will learn', 'sepo-lms'), __('One objective per line.', 'sepo-lms'), 5);
        echo '<p class="description">' . esc_html__('The excerpt (Summary) appears at the top of the outline and on course cards.', 'sepo-lms') . '</p>';
    }

    public static function peopleBox(WP_Post $post): void
    {
        self::nonce();
        echo '<div class="slms-cols"><div>';
        self::select($post, 'teacher', __('Teacher', 'sepo-lms'), self::choices(PostTypes::TEACHER), __('— None —', 'sepo-lms'));
        echo '</div><div>';
        self::select($post, 'school', __('School', 'sepo-lms'), self::choices(PostTypes::SCHOOL), __('— None —', 'sepo-lms'));
        echo '</div></div>';
        self::area($post, 'teacher_bio', __('About the teacher, for this course', 'sepo-lms'), __('Optional. Empty uses the teacher’s own summary.', 'sepo-lms'), 3);
    }

    public static function methodsBox(WP_Post $post): void
    {
        self::nonce();
        $chosen = array_map('strval', (array)get_post_meta($post->ID, '_slms_methods', true));
        $methods = Settings::methods();
        echo '<input type="hidden" name="slms_methods_present" value="1">';
        if ($methods === []) {
            echo '<p>' . wp_kses_post(sprintf(
                /* translators: %s: settings link */
                __('No payment methods are enabled. Add them in %s.', 'sepo-lms'),
                '<a href="' . esc_url(admin_url('admin.php?page=sepo-lms-settings#methods')) . '">' . esc_html__('LMS settings', 'sepo-lms') . '</a>'
            )) . '</p>';
            return;
        }
        echo '<p class="description">' . esc_html__('Tick the methods learners can use for this course. None ticked means all of them.', 'sepo-lms') . '</p>';
        foreach ($methods as $id => $m) {
            echo '<label class="slms-block"><input type="checkbox" name="slms_methods[]" value="' . esc_attr($id) . '" ' . checked(in_array($id, $chosen, true), true, false) . '> '
                . esc_html($m['label']) . ' <span class="description">(' . esc_html($m['type']) . ')</span></label>';
        }
    }

    public static function relatedBox(WP_Post $post): void
    {
        self::nonce();
        $chosen = array_map('intval', (array)get_post_meta($post->ID, '_slms_related', true));
        $all = self::choices(PostTypes::COURSE);
        unset($all[$post->ID]);
        echo '<input type="hidden" name="slms_related_present" value="1">';
        if ($all === []) {
            echo '<p class="description">' . esc_html__('No other courses yet.', 'sepo-lms') . '</p>';
            return;
        }
        echo '<div class="slms-scroll">';
        foreach ($all as $id => $title) {
            echo '<label class="slms-block"><input type="checkbox" name="slms_related[]" value="' . (int)$id . '" ' . checked(in_array($id, $chosen, true), true, false) . '> ' . esc_html($title) . '</label>';
        }
        echo '</div>';
    }

    public static function enrolmentsBox(WP_Post $post): void
    {
        $n = Enrollments::count($post->ID);
        /* translators: %d: number of learners */
        echo '<p><strong>' . esc_html(sprintf(_n('%d learner enrolled', '%d learners enrolled', $n, 'sepo-lms'), $n)) . '</strong></p>';
        if (current_user_can(Activator::CAP)) {
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=sepo-lms-enrollments&course=' . $post->ID)) . '">' . esc_html__('View enrolments', 'sepo-lms') . '</a></p>';
        }
    }

    public static function outlineBox(WP_Post $post): void
    {
        self::nonce();
        $items = Course::items($post->ID, true);
        $new = static fn(string $type): string => admin_url('post-new.php?post_type=' . $type . '&slms_course=' . $post->ID);
        ?>
        <p class="description"><?php esc_html_e('Lessons and quizzes that belong to this course. Drag to reorder; the order is saved with the course.', 'sepo-lms'); ?></p>
        <?php if ($items === []): ?>
            <p class="slms-empty-box"><?php esc_html_e('Nothing in this course yet.', 'sepo-lms'); ?></p>
        <?php else: ?>
            <ol class="slms-outline-admin" data-slms-sortable>
                <?php foreach ($items as $it): ?>
                    <li>
                        <span class="dashicons dashicons-menu slms-handle" aria-hidden="true"></span>
                        <input type="hidden" name="slms_outline[]" value="<?php echo (int)$it->ID; ?>">
                        <span class="slms-type slms-type--<?php echo $it->post_type === PostTypes::QUIZ ? 'quiz' : 'lesson'; ?>"><?php echo $it->post_type === PostTypes::QUIZ ? esc_html__('Quiz', 'sepo-lms') : esc_html__('Lesson', 'sepo-lms'); ?></span>
                        <a href="<?php echo esc_url((string)get_edit_post_link($it->ID)); ?>"><?php echo esc_html(get_the_title($it) ?: __('(no title)', 'sepo-lms')); ?></a>
                        <?php if ($it->post_status !== 'publish'): ?><em class="description">— <?php echo esc_html(get_post_status_object($it->post_status)->label ?? $it->post_status); ?></em><?php endif; ?>
                        <?php $from = (string)get_post_meta($it->ID, '_slms_available_from', true); ?>
                        <?php if ($from !== ''): ?><span class="description"><?php echo esc_html(sprintf(/* translators: %s: date */ __('opens %s', 'sepo-lms'), Helpers::date($from))); ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <p>
            <?php if ($post->post_status === 'auto-draft'): ?>
                <span class="description"><?php esc_html_e('Save the course once, then add lessons and quizzes.', 'sepo-lms'); ?></span>
            <?php else: ?>
                <a class="button" href="<?php echo esc_url($new(PostTypes::LESSON)); ?>"><?php esc_html_e('Add lesson', 'sepo-lms'); ?></a>
                <a class="button" href="<?php echo esc_url($new(PostTypes::QUIZ)); ?>"><?php esc_html_e('Add quiz', 'sepo-lms'); ?></a>
            <?php endif; ?>
        </p>
        <?php
    }

    // ------------------------------------------------------- lesson and quiz

    public static function courseBox(WP_Post $post): void
    {
        self::nonce();
        $cur = self::m($post, 'course');
        if ($cur === '' && isset($_GET['slms_course'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $cur = (string)absint($_GET['slms_course']); // phpcs:ignore WordPress.Security.NonceVerification
        }
        echo '<p class="slms-f"><label for="slms_course">' . esc_html__('Belongs to', 'sepo-lms') . '</label><select id="slms_course" name="slms[course]" class="widefat"><option value="">' . esc_html__('— No course —', 'sepo-lms') . '</option>';
        foreach (self::choices(PostTypes::COURSE) as $id => $title) {
            echo '<option value="' . (int)$id . '" ' . selected($cur, (string)$id, false) . '>' . esc_html($title) . '</option>';
        }
        echo '</select></p>';
        self::text($post, 'available_from', __('Opens on', 'sepo-lms'), 'date', __('Optional. Locked until this date.', 'sepo-lms'));
        echo '<p class="description">' . esc_html__('Position in the course is the “Order” number below, or drag it in the course’s Outline box.', 'sepo-lms') . '</p>';
        if ($post->post_type === PostTypes::LESSON) {
            echo '<p class="description">' . esc_html__('The excerpt shows as a short note under the lesson in the outline.', 'sepo-lms') . '</p>';
        }
    }

    // ---------------------------------------------------- school and teacher

    private static function imageField(WP_Post $post, string $key, string $label): void
    {
        $val = self::m($post, $key);
        $url = Helpers::image($val, 'medium');
        ?>
        <div class="slms-f slms-image" data-slms-image>
            <label><?php echo esc_html($label); ?></label>
            <input type="hidden" name="slms[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>" data-slms-image-id>
            <div class="slms-image-prev" data-slms-image-prev><?php echo $url !== '' ? '<img src="' . esc_url($url) . '" alt="">' : ''; ?></div>
            <button type="button" class="button" data-slms-image-pick><?php esc_html_e('Choose image', 'sepo-lms'); ?></button>
            <button type="button" class="button-link-delete" data-slms-image-clear<?php echo $url === '' ? ' hidden' : ''; ?>><?php esc_html_e('Remove', 'sepo-lms'); ?></button>
        </div>
        <?php
    }

    public static function schoolBox(WP_Post $post): void
    {
        self::nonce();
        echo '<p class="description">' . esc_html__('The featured image is the school’s logo. The excerpt is the short description on school cards.', 'sepo-lms') . '</p>';
        echo '<div class="slms-cols"><div>';
        self::text($post, 'location', __('Location', 'sepo-lms'));
        self::text($post, 'phone', __('Phone', 'sepo-lms'), 'tel');
        self::text($post, 'email', __('Email', 'sepo-lms'), 'email');
        self::text($post, 'website', __('Website', 'sepo-lms'), 'url');
        echo '</div><div>';
        self::text($post, 'registration_number', __('Registration number', 'sepo-lms'));
        self::text($post, 'tax_label', __('Tax number label', 'sepo-lms'), 'text', '', 'placeholder="TPIN"');
        self::text($post, 'tax_number', __('Tax number', 'sepo-lms'));
        self::text($post, 'tax_country', __('Tax country', 'sepo-lms'));
        echo '</div></div>';
        self::area($post, 'accreditations', __('Accreditations', 'sepo-lms'), __('One per line.', 'sepo-lms'), 3);
        self::imageField($post, 'cover', __('Cover image', 'sepo-lms'));
    }

    public static function teacherBox(WP_Post $post): void
    {
        self::nonce();
        echo '<p class="description">' . esc_html__('The featured image is the teacher’s photo. The excerpt is the short bio shown on courses.', 'sepo-lms') . '</p>';
        echo '<div class="slms-cols"><div>';
        self::text($post, 'role', __('Role or title', 'sepo-lms'), 'text', '', 'placeholder="' . esc_attr__('Senior lecturer', 'sepo-lms') . '"');
        self::select($post, 'school', __('School', 'sepo-lms'), self::choices(PostTypes::SCHOOL), __('— None —', 'sepo-lms'));
        self::text($post, 'email', __('Email', 'sepo-lms'), 'email');
        echo '</div><div>';
        self::text($post, 'phone', __('Phone', 'sepo-lms'), 'tel');
        self::text($post, 'website', __('Website', 'sepo-lms'), 'url');
        echo '</div></div>';
        self::area($post, 'subjects', __('Subjects', 'sepo-lms'), __('One per line.', 'sepo-lms'), 3);
        self::area($post, 'socials', __('Social links', 'sepo-lms'), __('One per line: Label | https://…', 'sepo-lms'), 3);
        self::imageField($post, 'cover', __('Cover image', 'sepo-lms'));
    }

    // ------------------------------------------------------------------ save

    public static function save(int $postId, WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST[self::NONCE])), 'slms_save_meta')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $in = isset($_POST['slms']) && is_array($_POST['slms']) ? wp_unslash($_POST['slms']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field below

        $fields = match ($post->post_type) {
            PostTypes::COURSE  => [
                'price' => 'money', 'currency' => 'currency', 'discount_rate' => 'percent', 'discount_label' => 'text', 'hide_original_price' => 'bool',
                'tax_rate' => 'percent', 'tax_type' => 'text', 'tax_country' => 'text', 'level' => 'text', 'sequential' => 'bool',
                'start_date' => 'date', 'end_date' => 'date', 'objectives' => 'textarea', 'teacher' => 'post', 'school' => 'post', 'teacher_bio' => 'textarea',
            ],
            PostTypes::LESSON  => ['course' => 'post', 'available_from' => 'date'],
            PostTypes::QUIZ    => ['course' => 'post', 'available_from' => 'date'],
            PostTypes::SCHOOL  => [
                'location' => 'text', 'phone' => 'text', 'email' => 'email', 'website' => 'url', 'registration_number' => 'text',
                'tax_label' => 'text', 'tax_number' => 'text', 'tax_country' => 'text', 'accreditations' => 'textarea', 'cover' => 'image',
            ],
            PostTypes::TEACHER => [
                'role' => 'text', 'school' => 'post', 'email' => 'email', 'phone' => 'text', 'website' => 'url',
                'subjects' => 'textarea', 'socials' => 'textarea', 'cover' => 'image',
            ],
            default => [],
        };

        $postTypeFor = ['teacher' => PostTypes::TEACHER, 'school' => PostTypes::SCHOOL, 'course' => PostTypes::COURSE];

        foreach ($fields as $key => $kind) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $raw = is_scalar($in[$key]) ? (string)$in[$key] : '';
            $val = match ($kind) {
                'money'    => $raw === '' ? '' : (string)max(0, round((float)$raw, 2)),
                'percent'  => $raw === '' ? '' : (string)min(100, max(0, round((float)$raw, 2))),
                'currency' => strlen($c = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '')) === 3 ? $c : '',
                'bool'     => $raw === '1' ? '1' : '0',
                'date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '',
                'textarea' => sanitize_textarea_field($raw),
                'email'    => sanitize_email($raw),
                'url'      => esc_url_raw($raw, ['http', 'https']),
                'image'    => (string)(absint($raw) > 0 && wp_attachment_is_image(absint($raw)) ? absint($raw) : ''),
                'post'     => (string)(absint($raw) > 0 && get_post_type(absint($raw)) === ($postTypeFor[$key] ?? '') ? absint($raw) : ''),
                default    => sanitize_text_field($raw),
            };
            if ($val === '') {
                delete_post_meta($postId, '_slms_' . $key);
            } else {
                update_post_meta($postId, '_slms_' . $key, $val);
            }
        }

        if ($post->post_type === PostTypes::COURSE) {
            if (isset($_POST['slms_methods_present'])) {
                $valid = array_keys(Settings::methods());
                $methods = isset($_POST['slms_methods']) ? array_map('sanitize_key', (array)wp_unslash($_POST['slms_methods'])) : [];
                update_post_meta($postId, '_slms_methods', array_values(array_intersect($methods, $valid)));
            }
            if (isset($_POST['slms_related_present'])) {
                $ids = isset($_POST['slms_related']) ? array_map('absint', (array)wp_unslash($_POST['slms_related'])) : [];
                $ids = array_values(array_filter($ids, static fn($id) => $id !== $postId && get_post_type($id) === PostTypes::COURSE));
                update_post_meta($postId, '_slms_related', $ids);
            }
            if (isset($_POST['slms_outline']) && is_array($_POST['slms_outline'])) {
                self::saveOrder($postId, array_map('absint', wp_unslash($_POST['slms_outline'])));
            }
            Course::flush($postId);
        }
    }

    /**
     * Write the outline order straight to menu_order, so saving the course
     * does not trigger a save of every lesson.
     *
     * @param list<int> $ids
     */
    private static function saveOrder(int $courseId, array $ids): void
    {
        global $wpdb;
        $pos = 0;
        foreach ($ids as $id) {
            if ($id <= 0 || PostTypes::courseOf($id) !== $courseId || !current_user_can('edit_post', $id)) {
                continue;
            }
            $pos += 10;
            $wpdb->update($wpdb->posts, ['menu_order' => $pos], ['ID' => $id]); // phpcs:ignore WordPress.DB
            clean_post_cache($id);
        }
    }
}
