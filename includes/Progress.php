<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Lesson and quiz completion, one row per learner per outline item.
 */
final class Progress
{
    /** @var array<string,array<int,true>> */
    private static array $cache = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_progress';
    }

    /** @return array<int,true> item IDs the learner has completed in this course */
    public static function doneSet(int $courseId, int $userId): array
    {
        if ($courseId <= 0 || $userId <= 0) {
            return [];
        }
        $k = $courseId . ':' . $userId;
        if (!isset(self::$cache[$k])) {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare('SELECT item_id FROM ' . self::table() . ' WHERE course_id = %d AND user_id = %d', $courseId, $userId)); // phpcs:ignore WordPress.DB
            self::$cache[$k] = array_fill_keys(array_map('intval', is_array($ids) ? $ids : []), true);
        }
        return self::$cache[$k];
    }

    public static function isDone(int $courseId, int $userId, int $itemId): bool
    {
        return isset(self::doneSet($courseId, $userId)[$itemId]);
    }

    public static function set(int $courseId, int $userId, int $itemId, bool $done): void
    {
        global $wpdb;
        if ($done) {
            $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
                'INSERT INTO ' . self::table() . ' (course_id, user_id, item_id, done_at) VALUES (%d, %d, %d, %s) ON DUPLICATE KEY UPDATE done_at = done_at',
                $courseId,
                $userId,
                $itemId,
                current_time('mysql', true)
            ));
        } else {
            $wpdb->delete(self::table(), ['course_id' => $courseId, 'user_id' => $userId, 'item_id' => $itemId]); // phpcs:ignore WordPress.DB
        }
        unset(self::$cache[$courseId . ':' . $userId]);
    }

    public static function deleteForCourse(int $courseId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['course_id' => $courseId]); // phpcs:ignore WordPress.DB
        self::$cache = [];
    }

    public static function deleteForItem(int $itemId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['item_id' => $itemId]); // phpcs:ignore WordPress.DB
        self::$cache = [];
    }

    public static function deleteForUser(int $userId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['user_id' => $userId]); // phpcs:ignore WordPress.DB
        self::$cache = [];
    }
}
