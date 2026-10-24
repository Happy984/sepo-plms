<?php
/**
 * Sepo LMS — a lesson the viewer cannot open yet.
 *
 *
 * @var WP_Post                  $post
 * @var array<string,mixed>|null $course
 * @var string                   $reason
 * @var string                   $code   login | enrol | pending | locked
 */

use SepoLms\Helpers;
use SepoLms\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slms-wrap">
    <div class="slms-locked">
        <div class="slms-locked-icon"><?php echo Icons::get('lock', 40); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
        <h1 class="slms-h2"><?php echo esc_html(get_the_title($post)); ?></h1>
        <p><?php echo esc_html($reason); ?></p>
        <div class="slms-row slms-row--center">
            <?php if ($code === 'login'): ?>
                <a class="slms-btn slms-btn--primary" href="<?php echo esc_url(Helpers::loginUrl((string)get_permalink($post))); ?>"><?php esc_html_e('Sign in', 'sepo-lms'); ?></a>
            <?php endif; ?>
            <?php if ($course !== null): ?>
                <a class="slms-btn<?php echo $code === 'login' ? '' : ' slms-btn--primary'; ?>" href="<?php echo esc_url($course['url']); ?>">
                    <?php
                    /* translators: %s: course title */
                    echo esc_html(sprintf(__('Go to %s', 'sepo-lms'), $course['title']));
                    ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
