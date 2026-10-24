<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Course reviews: a 1–5 rating and a short text, one per learner per
 * course. Writing again replaces the learner's earlier review.
 */
final class Reviews
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_reviews';
    }

    /** @return list<array<string,mixed>> */
    public static function forCourse(int $courseId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE course_id = %d ORDER BY created DESC', $courseId), ARRAY_A); // phpcs:ignore WordPress.DB
        return array_map(static function (array $r): array {
            $r['id'] = (int)$r['id'];
            $r['user_id'] = (int)$r['user_id'];
            $r['rating'] = (int)$r['rating'];
            return $r;
        }, is_array($rows) ? $rows : []);
    }

    /** @return array{count:int,average:float} */
    public static function summary(int $courseId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS n, AVG(rating) AS a FROM ' . self::table() . ' WHERE course_id = %d', $courseId), ARRAY_A); // phpcs:ignore WordPress.DB
        return ['count' => (int)($row['n'] ?? 0), 'average' => round((float)($row['a'] ?? 0), 1)];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? $row : null;
    }

    public static function save(int $courseId, int $userId, string $name, int $rating, string $body): void
    {
        global $wpdb;
        $rating = max(1, min(5, $rating));
        $body = mb_substr(trim(sanitize_textarea_field($body)), 0, 2000);
        if ($body === '') {
            throw new \InvalidArgumentException(__('Write a few words about the course.', 'sepo-lms'));
        }
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'INSERT INTO ' . self::table() . ' (course_id, user_id, name, rating, body, created) VALUES (%d, %d, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE name = VALUES(name), rating = VALUES(rating), body = VALUES(body), created = VALUES(created)',
            $courseId,
            $userId,
            mb_substr(sanitize_text_field($name), 0, 120),
            $rating,
            $body,
            current_time('mysql', true)
        ));
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        return (bool)$wpdb->delete(self::table(), ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    public static function deleteForCourse(int $courseId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['course_id' => $courseId]); // phpcs:ignore WordPress.DB
    }
}
