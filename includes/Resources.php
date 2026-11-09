<?php

declare(strict_types=1);

namespace SepoLms;

defined('ABSPATH') || exit;

/**
 * Course resources: documents and recordings a teacher adds to a course
 * from the Media Library. Learners in the classroom find them on the
 * Resources tab, next to the Forums: documents to download, and audio and
 * video to play on the page, like a podcast feed.
 */
final class Resources
{
    public const META = '_slms_resources';

    /** @return list<array{id:int,title:string,note:string}> */
    public static function raw(int $courseId): array
    {
        $v = get_post_meta($courseId, self::META, true);
        return is_array($v) ? array_values(array_filter($v, static fn($r): bool => is_array($r) && !empty($r['id']))) : [];
    }

    /** The resources ready to show, newest first as set by the teacher. @return list<array<string,mixed>> */
    public static function forCourse(int $courseId): array
    {
        $out = [];
        foreach (self::raw($courseId) as $r) {
            $id = (int) $r['id'];
            $url = (string) wp_get_attachment_url($id);
            if ($url === '') {
                continue;
            }
            $mime = (string) get_post_mime_type($id);
            $file = (string) get_attached_file($id);
            $kind = str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'video/') ? 'video' : 'file');
            $meta = wp_get_attachment_metadata($id);
            $out[] = [
                'id'       => $id,
                'title'    => $r['title'] !== '' ? $r['title'] : (string) get_the_title($id),
                'note'     => (string) $r['note'],
                'url'      => $url,
                'mime'     => $mime,
                'kind'     => $kind,
                'ext'      => strtoupper((string) pathinfo($file !== '' ? $file : $url, PATHINFO_EXTENSION)),
                'size'     => $file !== '' && is_file($file) ? size_format((int) filesize($file)) : '',
                'length'   => is_array($meta) && !empty($meta['length_formatted']) ? (string) $meta['length_formatted'] : '',
                'date'     => (string) get_the_date('', $id),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $in raw posted rows */
    public static function save(int $courseId, array $in): void
    {
        $rows = [];
        foreach ($in as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = absint($r['id'] ?? 0);
            if ($id <= 0 || get_post_type($id) !== 'attachment') {
                continue;
            }
            $rows[] = [
                'id'    => $id,
                'title' => mb_substr(sanitize_text_field((string) ($r['title'] ?? '')), 0, 150),
                'note'  => mb_substr(sanitize_textarea_field((string) ($r['note'] ?? '')), 0, 500),
            ];
        }
        if ($rows === []) {
            delete_post_meta($courseId, self::META);
        } else {
            update_post_meta($courseId, self::META, array_slice($rows, 0, 200));
        }
    }
}
