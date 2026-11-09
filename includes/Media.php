<?php

declare(strict_types=1);

namespace SepoLms;

defined('ABSPATH') || exit;

/**
 * The audio player and the file preview used for course resources.
 *
 * The player is plain markup with a native <audio> inside it, so it plays
 * even before its script runs; assets/js/media.js turns it into the custom
 * player. Preview buttons open the file in a dialog on the page.
 */
final class Media
{
    /** Which kind of preview a file can have: pdf, image, text, audio, video, or '' for none. */
    public static function previewKind(string $mime, string $ext): string
    {
        $ext = strtolower($ext);
        if (str_starts_with($mime, 'image/') && $ext !== 'svg') {
            return 'image';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return 'pdf';
        }
        if (in_array($ext, ['txt', 'md', 'csv', 'json', 'log'], true) || $mime === 'text/plain' || $mime === 'text/csv') {
            return 'text';
        }
        return '';
    }

    /** The audio player for one file. */
    public static function player(string $url, string $title, string $sub = '', string $download = ''): string
    {
        $svg = static fn(string $paths, int $s = 22): string => '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
        $back = $svg('<path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 3 3 9 9 9"/><text x="12" y="15.5" font-size="7" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none">15</text>');
        $fwd = $svg('<path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/><text x="12" y="15.5" font-size="7" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none">15</text>');
        $vol = $svg('<path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>', 20);
        $mute = $svg('<path d="M11 5 6 9H3v6h3l5 4z"/><line x1="22" y1="9" x2="16" y2="15"/><line x1="16" y1="9" x2="22" y2="15"/>', 20);
        $dl = $svg('<path d="M12 4v11"/><polyline points="7 10 12 15 17 10"/><path d="M5 20h14"/>', 20);
        $note = $svg('<path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/>', 26);

        ob_start();
        ?>
        <div class="slms-ap" data-slms-audio>
            <span class="slms-ap__art" aria-hidden="true"><?php echo $note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own SVG. ?></span>
            <div class="slms-ap__body">
                <div class="slms-ap__head">
                    <strong class="slms-ap__title"><?php echo esc_html($title); ?></strong>
                    <?php if ($sub !== ''): ?><span class="slms-ap__sub"><?php echo esc_html($sub); ?></span><?php endif; ?>
                </div>
                <div class="slms-ap__controls">
                    <button type="button" class="slms-ap__btn" data-ap="back" aria-label="<?php esc_attr_e('Back 15 seconds', 'sepo-lms'); ?>" title="<?php esc_attr_e('Back 15 seconds', 'sepo-lms'); ?>"><?php echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own SVG. ?></button>
                    <button type="button" class="slms-ap__play" data-ap="play" aria-label="<?php esc_attr_e('Play', 'sepo-lms'); ?>">
                        <svg class="slms-ap__i-play" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11.04-6.86a1 1 0 0 0 0-1.72L9.5 4.28A1 1 0 0 0 8 5.14z"/></svg>
                        <svg class="slms-ap__i-pause" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><rect x="6" y="4.5" width="4" height="15" rx="1.2" fill="currentColor"/><rect x="14" y="4.5" width="4" height="15" rx="1.2" fill="currentColor"/></svg>
                        <span class="slms-ap__spin" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="slms-ap__btn" data-ap="fwd" aria-label="<?php esc_attr_e('Forward 15 seconds', 'sepo-lms'); ?>" title="<?php esc_attr_e('Forward 15 seconds', 'sepo-lms'); ?>"><?php echo $fwd; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own SVG. ?></button>
                    <span class="slms-ap__spacer"></span>
                    <button type="button" class="slms-ap__btn slms-ap__rate" data-ap="rate" aria-label="<?php esc_attr_e('Playback speed', 'sepo-lms'); ?>" title="<?php esc_attr_e('Playback speed', 'sepo-lms'); ?>">1×</button>
                    <span class="slms-ap__vol">
                        <button type="button" class="slms-ap__btn" data-ap="mute" aria-label="<?php esc_attr_e('Mute', 'sepo-lms'); ?>" title="<?php esc_attr_e('Mute', 'sepo-lms'); ?>"><span class="slms-ap__i-vol"><?php echo $vol; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own SVG. ?></span><span class="slms-ap__i-muted"><?php echo $mute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own SVG. ?></span></button>
                        <input type="range" min="0" max="1" step="0.05" value="1" data-ap="volume" aria-label="<?php esc_attr_e('Volume', 'sepo-lms'); ?>">
                    </span>
                    <?php if ($download !== ''): ?>
                        <a class="slms-ap__btn" href="<?php echo esc_url($download); ?>" download aria-label="<?php esc_attr_e('Download', 'sepo-lms'); ?>" title="<?php esc_attr_e('Download', 'sepo-lms'); ?>"><?php echo $dl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own SVG. ?></a>
                    <?php endif; ?>
                </div>
                <div class="slms-ap__timeline">
                    <span class="slms-ap__time" data-ap="cur">0:00</span>
                    <div class="slms-ap__bar" data-ap="bar" role="slider" tabindex="0" aria-label="<?php esc_attr_e('Seek', 'sepo-lms'); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-valuetext="0:00">
                        <span class="slms-ap__buffer" data-ap="buffer"></span><span class="slms-ap__fill" data-ap="fill"></span><span class="slms-ap__knob" data-ap="knob"></span>
                    </div>
                    <span class="slms-ap__time slms-ap__time--left" data-ap="left">--:--</span>
                </div>
            </div>
            <audio preload="metadata" controls src="<?php echo esc_url($url); ?>"></audio>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
