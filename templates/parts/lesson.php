<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the method that includes this file.
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
 * @var int                      $number  the lesson's place in its course, 0 outside a course
 * @var int                      $total   items in the course
 * @var int                      $minutes reading time
 * @var string                   $teacher the course teacher, or the lesson's author
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
    <header class="slms-lhead<?php echo $number > 0 ? '' : ' slms-lhead--plain'; ?>">
        <?php if ($number > 0): ?>
            <div class="slms-lhead__mark" aria-hidden="true">
                <span class="slms-lhead__num"><?php echo (int) $number; ?></span>
                <span class="slms-lhead__rule"></span>
                <span class="slms-lhead__kicker"><?php echo esc_html(sprintf(/* translators: %d: number of items in the course */ __('of %d', 'sepo-lms'), $total)); ?></span>
            </div>
        <?php endif; ?>
        <div class="slms-lhead__main">
            <h1 class="slms-lhead__title">
                <?php if ($number > 0): ?><span class="screen-reader-text"><?php echo esc_html(sprintf(/* translators: 1: item number, 2: number of items */ __('Lesson %1$d of %2$d:', 'sepo-lms'), $number, $total)); ?> </span><?php endif; ?>
                <?php echo esc_html(get_the_title($post)); ?>
            </h1>
            <div class="slms-lhead__meta">
                <?php if ($teacher !== ''): ?>
                    <span class="slms-lhead__by"><?php echo esc_html(sprintf(/* translators: %s: teacher name */ __('by %s', 'sepo-lms'), $teacher)); ?></span>
                <?php endif; ?>
                <span class="slms-lhead__tags">
                    <?php if ($course !== null): ?>
                        <a href="<?php echo esc_url($course['url']); ?>"><?php echo esc_html($course['title']); ?></a><span aria-hidden="true"> · </span>
                    <?php endif; ?>
                    <span><?php echo esc_html(sprintf(/* translators: %d: minutes */ _n('%d min read', '%d min read', $minutes, 'sepo-lms'), $minutes)); ?></span>
                </span>
            </div>
        </div>
    </header>

    <?php if (count($parts) > 1): ?>
        <div class="slms-cards" data-slms-cards data-count="<?php echo count($parts); ?>">
            <?php foreach ($parts as $i => $part): ?>
                <section class="slms-content slms-cardpage" data-card="<?php echo (int)$i + 1; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: 1: part number, 2: number of parts */ __('Part %1$d of %2$d', 'sepo-lms'), $i + 1, count($parts))); ?>"><?php echo $part; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></section>
            <?php endforeach; ?>
            <nav class="slms-cardnav" aria-label="<?php esc_attr_e('Parts of this lesson', 'sepo-lms'); ?>" hidden>
                <button type="button" class="slms-btn" data-card-prev><?php esc_html_e('← Previous', 'sepo-lms'); ?></button>
                <span class="slms-cardnav__dots" role="group" aria-label="<?php esc_attr_e('Go to part', 'sepo-lms'); ?>">
                    <?php foreach ($parts as $i => $part): ?>
                        <button type="button" class="slms-cardnav__dot" data-card-go="<?php echo (int)$i + 1; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %d: part number */ __('Part %d', 'sepo-lms'), $i + 1)); ?>"></button>
                    <?php endforeach; ?>
                </span>
                <span class="slms-cardnav__count" aria-live="polite" data-card-count></span>
                <button type="button" class="slms-btn slms-btn--primary" data-card-next><?php esc_html_e('Next →', 'sepo-lms'); ?></button>
            </nav>
        </div>
    <?php else: ?>
        <div class="slms-content"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></div>
    <?php endif; ?>

    <?php if ($canTrack): ?>
        <div class="slms-complete"<?php echo count($parts) > 1 ? ' data-slms-cards-end' : ''; ?>>
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
