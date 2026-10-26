<?php
/**
 * Sepo LMS — a lesson.
 *
 * Customise: place a copy at your-theme/sepo-lms/parts/lesson.php
 *
 * @var WP_Post                  $post
 * @var array<string,mixed>|null $course
 * @var string                   $body
 * @var array<string,mixed>|null $prev
 * @var array<string,mixed>|null $next
 * @var bool                     $done
 * @var bool                     $canTrack
 * @var array{course:int,item:int}|null $study
 */

use SepoLms\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$navLink = static function (?array $item, string $dir): string {
    if ($item === null) {
        return '<span></span>';
    }
    $label = esc_html($item['title']);
    if (!$item['accessible']) {
        return '<span class="slms-nav-btn is-locked" title="' . esc_attr($item['reason']) . '">'
            . ($dir === 'prev' ? '← ' : '') . $label . ($dir === 'next' ? ' ' . Icons::get('lock', 14) : '') . '</span>';
    }
    return '<a class="slms-nav-btn" rel="' . ($dir === 'prev' ? 'prev' : 'next') . '" href="' . esc_url($item['url']) . '">'
        . ($dir === 'prev' ? '← ' : '') . $label . ($dir === 'next' ? ' →' : '') . '</a>';
};
?>
<article class="slms-wrap slms-lesson">
    <?php if ($course !== null): ?>
        <a class="slms-crumb" href="<?php echo esc_url($course['url']); ?>"><?php echo Icons::get('book', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html($course['title']); ?></a>
    <?php endif; ?>
    <h1 class="slms-h1"><?php echo esc_html(get_the_title($post)); ?></h1>

    <div class="slms-content"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></div>

    <?php if ($canTrack): ?>
        <div class="slms-complete">
            <button type="button" class="slms-btn <?php echo $done ? 'slms-btn--done' : 'slms-btn--primary'; ?>"
                    data-slms-complete="<?php echo (int)$post->ID; ?>" data-done="<?php echo $done ? '1' : '0'; ?>">
                <?php echo $done ? esc_html__('✓ Completed — mark as not done', 'sepo-lms') : esc_html__('Mark as complete', 'sepo-lms'); ?>
            </button>
        </div>
    <?php endif; ?>

    <nav class="slms-nav" aria-label="<?php esc_attr_e('Lessons', 'sepo-lms'); ?>">
        <?php echo $navLink($prev, 'prev'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php echo $navLink($next, 'next'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    </nav>

    <?php if (!empty($study)): ?>
        <?php echo \SepoLms\Frontend::part('study-drawer', $study); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <?php endif; ?>
</article>
