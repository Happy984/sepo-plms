<?php
/**
 * Sepo LMS — inline sign-in prompt (used by [sepo_lms_my_courses]).
 *
 *
 * @var string $message
 * @var string $href
 * @var string $label
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slms-wrap">
    <p class="slms-note"><?php echo esc_html($message); ?> <a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a></p>
</div>
