<?php
/**
 * Sepo LMS — school index (archive and [sepo_lms_schools]).
 *
 * Customise: place a copy at your-theme/sepo-lms/parts/school-index.php
 *
 * @var string                    $title
 * @var string                    $intro
 * @var list<array<string,mixed>> $cards
 * @var int                       $perPage
 */

use SepoLms\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slms-wrap slms-index" data-slms-index data-per-page="<?php echo (int)$perPage; ?>">
    <?php if ($title !== ''): ?>
        <header class="slms-index-head">
            <h1 class="slms-h1"><?php echo esc_html($title); ?></h1>
            <?php if (trim(wp_strip_all_tags($intro)) !== ''): ?>
                <div class="slms-intro"><?php echo wp_kses_post($intro); ?></div>
            <?php endif; ?>
        </header>
    <?php endif; ?>

    <?php if ($cards === []): ?>
        <p class="slms-empty"><?php esc_html_e('No schools yet.', 'sepo-lms'); ?></p>
    <?php else: ?>
        <div class="slms-toolbar">
            <label class="slms-search">
                <?php echo Icons::get('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <span class="screen-reader-text"><?php esc_html_e('Search schools', 'sepo-lms'); ?></span>
                <input type="search" data-slms-q placeholder="<?php esc_attr_e('Search schools…', 'sepo-lms'); ?>">
            </label>
            <div class="slms-view" role="group" aria-label="<?php esc_attr_e('Layout', 'sepo-lms'); ?>">
                <button type="button" class="is-on" data-slms-view="grid" aria-pressed="true" title="<?php esc_attr_e('Grid', 'sepo-lms'); ?>"><?php echo Icons::get('grid', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
                <button type="button" data-slms-view="list" aria-pressed="false" title="<?php esc_attr_e('List', 'sepo-lms'); ?>"><?php echo Icons::get('list', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
            </div>
        </div>

        <div class="slms-grid" data-slms-items>
            <?php foreach ($cards as $s): ?>
                <a class="slms-card slms-card--school" href="<?php echo esc_url($s['url']); ?>" data-slms-item
                   data-search="<?php echo esc_attr(strtolower($s['name'] . ' ' . $s['location'] . ' ' . $s['summary'])); ?>">
                    <span class="slms-card-cover<?php echo $s['cover'] === '' ? ' is-empty' : ''; ?>"<?php echo $s['cover'] !== '' ? ' style="background-image:url(\'' . esc_url($s['cover']) . '\')"' : ''; ?>>
                        <?php if ($s['logo'] !== ''): ?>
                            <img class="slms-card-logo" src="<?php echo esc_url($s['logo']); ?>" alt="" loading="lazy">
                        <?php endif; ?>
                    </span>
                    <span class="slms-card-body">
                        <span class="slms-card-title"><?php echo esc_html($s['name']); ?></span>
                        <?php if ($s['location'] !== ''): ?>
                            <span class="slms-card-meta"><?php echo esc_html($s['location']); ?></span>
                        <?php endif; ?>
                        <?php if ($s['summary'] !== ''): ?>
                            <span class="slms-card-text"><?php echo esc_html(wp_trim_words($s['summary'], 22)); ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="slms-empty" data-slms-none hidden><?php esc_html_e('No schools match your search.', 'sepo-lms'); ?></p>
        <nav class="slms-pager" data-slms-pager aria-label="<?php esc_attr_e('Pages', 'sepo-lms'); ?>"></nav>
    <?php endif; ?>
</div>
