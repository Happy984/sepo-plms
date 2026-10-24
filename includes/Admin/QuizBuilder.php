<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\PostTypes;
use SepoLms\Quiz;
use WP_Post;

/**
 * Quiz settings and the drag-and-drop question builder.
 *
 * The builder (assets/js/admin.js) keeps the question list in a hidden
 * field as JSON; on save it is cleaned by Quiz::sanitizeQuestions().
 */
final class QuizBuilder
{
    private const NONCE = 'slms_quiz_nonce';

    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'register']);
        add_action('save_post_' . PostTypes::QUIZ, [self::class, 'save'], 10, 2);
    }

    public static function register(): void
    {
        add_meta_box('slms-questions', __('Questions', 'sepo-lms'), [self::class, 'builderBox'], PostTypes::QUIZ, 'normal', 'high');
        add_meta_box('slms-quiz-settings', __('Quiz settings', 'sepo-lms'), [self::class, 'settingsBox'], PostTypes::QUIZ, 'normal', 'default');
    }

    public static function builderBox(WP_Post $post): void
    {
        wp_nonce_field('slms_save_quiz', self::NONCE);
        $questions = Quiz::questions($post->ID);
        $types = [
            'multiplechoice' => __('Multiple choice — one answer', 'sepo-lms'),
            'multipleselect' => __('Checkboxes — several answers', 'sepo-lms'),
            'text'           => __('Short text', 'sepo-lms'),
            'longtext'       => __('Paragraph', 'sepo-lms'),
            'numeric'        => __('Number', 'sepo-lms'),
            'date'           => __('Date', 'sepo-lms'),
            'email'          => __('Email', 'sepo-lms'),
            'phone'          => __('Phone', 'sepo-lms'),
            'url'            => __('Web address', 'sepo-lms'),
        ];
        $i18n = [
            'question'      => __('Question', 'sepo-lms'),
            'questionPh'    => __('Type the question…', 'sepo-lms'),
            'type'          => __('Type', 'sepo-lms'),
            'points'        => __('Points', 'sepo-lms'),
            'required'      => __('Required', 'sepo-lms'),
            'options'       => __('Answers — tick the correct one', 'sepo-lms'),
            'optionsMulti'  => __('Answers — tick every correct one', 'sepo-lms'),
            'optionPh'      => __('Answer %s', 'sepo-lms'),
            'addOption'     => __('Add answer', 'sepo-lms'),
            'correct'       => __('Correct', 'sepo-lms'),
            'expected'      => __('Expected answer', 'sepo-lms'),
            'expectedHelp'  => __('Matched without regard to capitals. Several accepted answers can be separated with |. Leave empty and set points to 0 for an open, ungraded question.', 'sepo-lms'),
            'numericHelp'   => __('The exact number expected.', 'sepo-lms'),
            'explanation'   => __('Explanation (shown after submitting)', 'sepo-lms'),
            'image'         => __('Image', 'sepo-lms'),
            'addImage'      => __('Add image', 'sepo-lms'),
            'removeImage'   => __('Remove image', 'sepo-lms'),
            'duplicate'     => __('Duplicate', 'sepo-lms'),
            'delete'        => __('Delete', 'sepo-lms'),
            'collapse'      => __('Collapse', 'sepo-lms'),
            'expand'        => __('Expand', 'sepo-lms'),
            'removeOption'  => __('Remove answer', 'sepo-lms'),
            'moveUp'        => __('Move up', 'sepo-lms'),
            'moveDown'      => __('Move down', 'sepo-lms'),
            'confirmDelete' => __('Delete this question?', 'sepo-lms'),
            'noCorrect'     => __('No correct answer ticked', 'sepo-lms'),
            'noAnswer'      => __('No expected answer', 'sepo-lms'),
            'summary'       => __('%1$s questions · %2$s points', 'sepo-lms'),
            'empty'         => __('No questions yet. Add the first one below.', 'sepo-lms'),
            'untitled'      => __('(no question text)', 'sepo-lms'),
            'maxOptions'    => __('Up to %s answers per question.', 'sepo-lms'),
        ];
        ?>
        <div class="slms-qb" data-slms-qb
             data-types="<?php echo esc_attr((string)wp_json_encode($types)); ?>"
             data-i18n="<?php echo esc_attr((string)wp_json_encode($i18n)); ?>"
             data-max-options="<?php echo (int)Quiz::MAX_OPTIONS; ?>"
             data-max-questions="<?php echo (int)Quiz::MAX_QUESTIONS; ?>">
            <input type="hidden" name="slms_questions" value="<?php echo esc_attr((string)wp_json_encode($questions)); ?>" data-slms-qb-store>
            <div class="slms-qb-bar">
                <strong data-slms-qb-summary></strong>
                <span>
                    <button type="button" class="button-link" data-slms-qb-all="collapse"><?php esc_html_e('Collapse all', 'sepo-lms'); ?></button> ·
                    <button type="button" class="button-link" data-slms-qb-all="expand"><?php esc_html_e('Expand all', 'sepo-lms'); ?></button>
                </span>
            </div>
            <div class="slms-qb-list" data-slms-qb-list></div>
            <div class="slms-qb-add">
                <span><?php esc_html_e('Add a question:', 'sepo-lms'); ?></span>
                <?php foreach (['multiplechoice', 'multipleselect', 'text', 'longtext', 'numeric'] as $t): ?>
                    <button type="button" class="button" data-slms-qb-add="<?php echo esc_attr($t); ?>"><?php echo esc_html(trim(explode('—', $types[$t])[0])); ?></button>
                <?php endforeach; ?>
                <select data-slms-qb-add-other aria-label="<?php esc_attr_e('Other question types', 'sepo-lms'); ?>">
                    <option value=""><?php esc_html_e('More types…', 'sepo-lms'); ?></option>
                    <?php foreach (['date', 'email', 'phone', 'url'] as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($types[$t]); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><p><?php esc_html_e('The question builder needs JavaScript.', 'sepo-lms'); ?></p></noscript>
        </div>
        <?php
    }

    public static function settingsBox(WP_Post $post): void
    {
        $s = Quiz::settings($post);
        $num = static function (string $key, string $label, int|float $value, string $help, string $attrs): void {
            echo '<p class="slms-f"><label for="slms_q_' . esc_attr($key) . '">' . esc_html($label) . '</label>'
                . '<input type="number" id="slms_q_' . esc_attr($key) . '" name="slms_quiz[' . esc_attr($key) . ']" value="' . esc_attr((string)$value) . '" class="small-text" ' . $attrs . '> ' // phpcs:ignore WordPress.Security.EscapeOutput
                . ($help !== '' ? '<span class="description">' . esc_html($help) . '</span>' : '') . '</p>';
        };
        $flag = static function (string $key, string $label, bool $on, string $help = ''): void {
            echo '<p class="slms-f slms-f--check"><input type="hidden" name="slms_quiz[' . esc_attr($key) . ']" value="0"><label>'
                . '<input type="checkbox" name="slms_quiz[' . esc_attr($key) . ']" value="1" ' . checked($on, true, false) . '> ' . esc_html($label) . '</label>'
                . ($help !== '' ? '<br><span class="description">' . esc_html($help) . '</span>' : '') . '</p>';
        };
        echo '<div class="slms-cols"><div>';
        $num('passing_score', __('Pass mark (%)', 'sepo-lms'), $s['passing_score'], '', 'min="0" max="100"');
        $num('time_limit', __('Time limit (minutes)', 'sepo-lms'), $s['time_limit'], __('0 = no limit', 'sepo-lms'), 'min="0" max="1440"');
        $num('max_attempts', __('Attempts allowed', 'sepo-lms'), $s['max_attempts'], __('0 = unlimited (when retakes are on)', 'sepo-lms'), 'min="0" max="100"');
        $num('weight', __('Weight in the final grade (%)', 'sepo-lms'), $s['weight'], __('Used only when every quiz in the course has a weight.', 'sepo-lms'), 'min="0" max="100" step="0.01"');
        $num('per_page', __('Questions per page', 'sepo-lms'), $s['per_page'], '', 'min="1" max="50"');
        echo '</div><div>';
        $flag('allow_retake', __('Learners can retake it', 'sepo-lms'), $s['allow_retake']);
        $flag('require_login', __('Sign-in required', 'sepo-lms'), $s['require_login']);
        $flag('require_enrollment', __('Only learners enrolled in the course', 'sepo-lms'), $s['require_enrollment'], __('Also respects the course’s order and opening dates.', 'sepo-lms'));
        $flag('show_results', __('Show the score straight after submitting', 'sepo-lms'), $s['show_results']);
        $flag('review_answers', __('Let learners review their answers', 'sepo-lms'), $s['review_answers'], __('Correct answers are only revealed once no attempts remain.', 'sepo-lms'));
        $flag('collect_email', __('Ask guests for their email', 'sepo-lms'), $s['collect_email'], __('Only when sign-in is not required.', 'sepo-lms'));
        echo '</div></div>';
    }

    public static function save(int $postId, WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST[self::NONCE])), 'slms_save_quiz')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }

        if (isset($_POST['slms_questions'])) {
            $raw = json_decode((string)wp_unslash($_POST['slms_questions']), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded then cleaned field by field
            if (is_array($raw)) {
                $clean = Quiz::sanitizeQuestions($raw);
                update_post_meta($postId, '_slms_questions', wp_slash((string)wp_json_encode($clean)));
            }
        }

        $in = isset($_POST['slms_quiz']) && is_array($_POST['slms_quiz']) ? wp_unslash($_POST['slms_quiz']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $ints = ['passing_score' => [0, 100], 'time_limit' => [0, 1440], 'max_attempts' => [0, 100], 'per_page' => [1, 50]];
        foreach ($ints as $k => [$min, $max]) {
            if (isset($in[$k])) {
                update_post_meta($postId, '_slms_' . $k, (string)max($min, min($max, (int)$in[$k])));
            }
        }
        if (isset($in['weight'])) {
            update_post_meta($postId, '_slms_weight', (string)max(0, min(100, round((float)$in['weight'], 2))));
        }
        foreach (['allow_retake', 'require_login', 'require_enrollment', 'show_results', 'review_answers', 'collect_email'] as $k) {
            if (isset($in[$k])) {
                update_post_meta($postId, '_slms_' . $k, $in[$k] === '1' ? '1' : '0');
            }
        }
    }
}
