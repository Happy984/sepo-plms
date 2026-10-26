<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Signed, permission-checked downloads of private files:
 *   ?slms_dl=file&id={submission}&i={index}  an assignment file (the learner or course staff)
 *   ?slms_dl=rec&id={session}&i={part}       a proctoring recording part (course staff)
 *
 * Adding view=1 opens a file in the browser instead of downloading it, for
 * the formats browsers display themselves: PDF (the built-in PDF viewer),
 * images, plain text, audio and video. Other formats always download.
 */
final class Downloads
{
    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'maybeServe'], 0);
    }

    /** Extensions browsers display themselves, by how they are shown. */
    private const VIEWABLE = [
        'pdf'   => ['pdf' => 'application/pdf'],
        'image' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'],
        'text'  => ['txt' => 'text/plain', 'md' => 'text/plain', 'csv' => 'text/plain'],
        'audio' => ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg'],
        'video' => ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime'],
    ];

    /** How a file can be shown in the browser: pdf, image, text, audio, video, or '' (download only). */
    public static function viewKind(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        foreach (self::VIEWABLE as $kind => $types) {
            if (isset($types[$ext])) {
                return $kind;
            }
        }
        return '';
    }

    private static function viewMime(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        foreach (self::VIEWABLE as $kind => $types) {
            if (isset($types[$ext])) {
                return $kind === 'text' ? 'text/plain; charset=utf-8' : $types[$ext];
            }
        }
        return '';
    }

    public static function url(string $kind, int $id, int $index = 0, bool $view = false): string
    {
        $args = ['slms_dl' => $kind, 'id' => $id, 'i' => $index];
        if ($view) {
            $args['view'] = 1;
        }
        $args['_wpnonce'] = wp_create_nonce('slms_dl_' . $kind . '_' . $id);
        return add_query_arg($args, home_url('/'));
    }

    public static function maybeServe(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- verified below
        if (!isset($_GET['slms_dl'])) {
            return;
        }
        $kind = sanitize_key(wp_unslash((string)$_GET['slms_dl']));
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string)$_GET['_wpnonce'])) : '';
        $index = isset($_GET['i']) ? absint($_GET['i']) : 0;
        $view = !empty($_GET['view']);
        // phpcs:enable

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url(add_query_arg([]))));
            exit;
        }
        if (!in_array($kind, ['file', 'rec'], true) || $id <= 0 || !wp_verify_nonce($nonce, 'slms_dl_' . $kind . '_' . $id)) {
            self::deny(__('This link has expired. Go back and open it again.', 'sepo-lms'), 403);
        }

        if ($kind === 'file') {
            $row = Submissions::find($id);
            if ($row === null || ($row['user_id'] !== get_current_user_id() && !Access::canTeach((int)$row['course_id']))) {
                self::deny(__('File not found.', 'sepo-lms'), 404);
            }
            $file = $row['files'][$index] ?? null;
            $path = is_array($file) ? Storage::resolve((string)$file['path']) : '';
            if ($path === '') {
                self::deny(__('File not found.', 'sepo-lms'), 404);
            }
            $name = (string)$file['name'];
            $viewMime = $view ? self::viewMime($name) : '';
            if ($viewMime !== '') {
                Storage::stream($path, $viewMime, $name, true, self::viewKind($name) === 'pdf' ? 'pdf' : 'media');
            }
            Storage::stream($path, (string)$file['mime'], $name, false);
        }

        $session = Proctor::find($id);
        if ($session === null || !Access::canTeach((int)$session['course_id'])) {
            self::deny(__('Recording not found.', 'sepo-lms'), 404);
        }
        $part = max(1, $index);
        $path = $session['file'] !== '' ? Storage::resolve($session['file'] . '-' . $part . '.webm') : '';
        if ($path === '') {
            self::deny(__('Recording not found.', 'sepo-lms'), 404);
        }
        $mime = (string)$session['mime'];
        Storage::stream($path, str_starts_with($mime, 'video/') ? strtok($mime, ';') : 'video/webm', 'recording-' . $id . '-part-' . $part . '.webm', true, 'media');
    }

    private static function deny(string $message, int $status): void
    {
        wp_die(esc_html($message), '', ['response' => $status]);
    }
}
