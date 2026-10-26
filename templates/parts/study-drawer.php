<?php
/**
 * Sepo LMS — the study drawer: a private checklist and notes for this
 * course, opened from a button at the edge of the page.
 *
 * Customise: place a copy at your-theme/sepo-lms/parts/study-drawer.php
 *
 * @var int $course course ID (0 for none)
 * @var int $item   the lesson or assignment open on the page (0 for none)
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slms-study" data-slms-study data-course="<?php echo (int)$course; ?>" data-item="<?php echo (int)$item; ?>"
     data-item-title="<?php echo esc_attr($item > 0 ? get_the_title($item) : ''); ?>"
     data-notes-url="<?php echo esc_url(\SepoLms\Helpers::myCoursesUrl() . '#notes'); ?>">
    <button type="button" class="slms-study-fab" data-slms-study-open aria-expanded="false" aria-controls="slms-study-panel">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/></svg>
        <span><?php esc_html_e('Study tools', 'sepo-lms'); ?></span>
    </button>
    <aside class="slms-study-panel" id="slms-study-panel" role="dialog" aria-modal="false" aria-label="<?php esc_attr_e('Study tools', 'sepo-lms'); ?>" hidden>
        <header class="slms-study-head">
            <div class="slms-tabs slms-tabs--compact" role="tablist">
                <button type="button" class="slms-tab is-on" role="tab" aria-selected="true" data-slms-study-tab="todo"><?php esc_html_e('Checklist', 'sepo-lms'); ?></button>
                <button type="button" class="slms-tab" role="tab" aria-selected="false" data-slms-study-tab="notes"><?php esc_html_e('Notes', 'sepo-lms'); ?></button>
            </div>
            <button type="button" class="slms-icon-btn slms-study-close" data-slms-study-close aria-label="<?php esc_attr_e('Close study tools', 'sepo-lms'); ?>">✕</button>
        </header>
        <div class="slms-study-body" data-slms-study-pane="todo"></div>
        <div class="slms-study-body" data-slms-study-pane="notes" hidden></div>
    </aside>
</div>
