<?php
/**
 * Sepo LMS — course index (archive, category and [sepo_lms_courses]).
 *
 * Customise: place a copy at your-theme/sepo-lms/parts/course-index.php
 *
 * @var string                    $title
 * @var string                    $intro
 * @var list<array<string,mixed>> $cards
 * @var list<string>              $levels
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
        <p class="slms-empty"><?php esc_html_e('No courses yet.', 'sepo-lms'); ?></p>
    <?php else: ?>
        <div class="slms-toolbar">
            <label class="slms-search">
                <?php echo Icons::get('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <span class="screen-reader-text"><?php esc_html_e('Search courses', 'sepo-lms'); ?></span>
                <input type="search" data-slms-q placeholder="<?php esc_attr_e('Search courses…', 'sepo-lms'); ?>">
            </label>
            <?php if (count($levels) > 1): ?>
                <label class="slms-select">
                    <span class="screen-reader-text"><?php esc_html_e('Level', 'sepo-lms'); ?></span>
                    <select data-slms-filter="level">
                        <option value=""><?php esc_html_e('All levels', 'sepo-lms'); ?></option>
                        <?php foreach ($levels as $lv): ?>
                            <option value="<?php echo esc_attr(strtolower($lv)); ?>"><?php echo esc_html($lv); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <div class="slms-view" role="group" aria-label="<?php esc_attr_e('Layout', 'sepo-lms'); ?>">
                <button type="button" class="is-on" data-slms-view="grid" aria-pressed="true" title="<?php esc_attr_e('Grid', 'sepo-lms'); ?>"><?php echo Icons::get('grid', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
                <button type="button" data-slms-view="list" aria-pressed="false" title="<?php esc_attr_e('List', 'sepo-lms'); ?>"><?php echo Icons::get('list', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
            </div>
        </div>

        <div class="slms-grid" data-slms-items>
            <?php foreach ($cards as $c): ?>
                <a class="slms-card" href="<?php echo esc_url($c['url']); ?>" data-slms-item
                   data-search="<?php echo esc_attr($c['search']); ?>"
                   data-level="<?php echo esc_attr(strtolower((string)$c['level'])); ?>">
                    <span class="slms-card-img"<?php echo $c['image'] !== '' ? ' style="background-image:url(\'' . esc_url($c['image']) . '\')"' : ''; ?>></span>
                    <span class="slms-card-body">
                        <span class="slms-card-title"><?php echo esc_html($c['title']); ?></span>
                        <?php $meta = array_filter([$c['school'], $c['teacher']]); ?>
                        <?php if ($meta !== []): ?>
                            <span class="slms-card-meta"><?php echo esc_html(implode(' · ', $meta)); ?></span>
                        <?php endif; ?>
                        <?php if ($c['level'] !== ''): ?>
                            <span class="slms-pill"><?php echo esc_html($c['level']); ?></span>
                        <?php endif; ?>
                        <span class="slms-card-price">
                            <?php if ($c['original'] !== ''): ?>
                                <s class="slms-was"><?php echo esc_html($c['original']); ?></s>
                            <?php endif; ?>
                            <span class="slms-now"><?php echo esc_html($c['free'] ? __('Free', 'sepo-lms') : $c['price']); ?></span>
                            <?php if ($c['discount'] > 0 && !$c['free']): ?>
                                <span class="slms-off">−<?php echo esc_html(\SepoLms\Helpers::percent((float)$c['discount'])); ?>%</span>
                            <?php endif; ?>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="slms-empty" data-slms-none hidden><?php esc_html_e('No courses match your search.', 'sepo-lms'); ?></p>
        <nav class="slms-pager" data-slms-pager aria-label="<?php esc_attr_e('Pages', 'sepo-lms'); ?>"></nav>
    <?php endif; ?>
</div>
