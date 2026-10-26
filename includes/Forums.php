<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Course forums.
 *
 * Each course has a list of forums (set on the course edit screen), each of
 * one type:
 *   open           everyone in the course can start topics and reply
 *   post_first     everyone can post, but a learner sees other learners'
 *                  replies in a topic only after replying there themselves
 *   announcements  only course staff post; learners read
 *
 * Posts are stored in one table: a topic has parent_id 0, a reply points to
 * its topic. Bodies are plain text, shown with links and line breaks.
 */
final class Forums
{
    public const TYPES = ['open', 'post_first', 'announcements'];
    public const MAIL_HOOK = 'sepo_lms_forum_mail';

    public static function init(): void
    {
        add_action(self::MAIL_HOOK, [self::class, 'sendAnnouncement']);
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_forum_posts';
    }

    /** @return array<string,string> type => label */
    public static function typeLabels(): array
    {
        return [
            'open'          => __('Open discussion', 'sepo-lms'),
            'post_first'    => __('Post first, then see others', 'sepo-lms'),
            'announcements' => __('Announcements (staff only)', 'sepo-lms'),
        ];
    }

    /** @return list<array{key:string,title:string,type:string,description:string,email:bool}> */
    public static function defaults(): array
    {
        return [
            ['key' => 'announcements', 'title' => __('Announcements', 'sepo-lms'), 'type' => 'announcements', 'description' => __('News and updates from your teacher.', 'sepo-lms'), 'email' => true],
            ['key' => 'general', 'title' => __('General discussion', 'sepo-lms'), 'type' => 'open', 'description' => __('Ask questions and talk about the course.', 'sepo-lms'), 'email' => false],
        ];
    }

    /**
     * The course's forums (empty when forums are off).
     *
     * @return list<array{key:string,title:string,type:string,description:string,email:bool}>
     */
    public static function forCourse(int $courseId): array
    {
        if (!Settings::feature('forums') || $courseId <= 0 || get_post_meta($courseId, '_slms_forums_enabled', true) === '0') {
            return [];
        }
        $raw = get_post_meta($courseId, '_slms_forums', true);
        if (!is_array($raw)) {
            return self::defaults();
        }
        return self::clean($raw);
    }

    /**
     * @param array<mixed> $rows
     * @return list<array{key:string,title:string,type:string,description:string,email:bool}>
     */
    public static function clean(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $title = sanitize_text_field((string)($r['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $key = sanitize_key((string)($r['key'] ?? ''));
            if ($key === '') {
                $key = sanitize_key(sanitize_title($title)) ?: 'forum';
            }
            $base = substr($key, 0, 36);
            $key = $base;
            $n = 2;
            while (isset($seen[$key])) {
                $key = $base . '-' . $n++;
            }
            $seen[$key] = true;
            $type = in_array($r['type'] ?? '', self::TYPES, true) ? (string)$r['type'] : 'open';
            $out[] = [
                'key'         => $key,
                'title'       => $title,
                'type'        => $type,
                'description' => sanitize_textarea_field((string)($r['description'] ?? '')),
                'email'       => !empty($r['email']),
            ];
        }
        return $out;
    }

    /** @return array{key:string,title:string,type:string,description:string,email:bool}|null */
    public static function forum(int $courseId, string $key): ?array
    {
        foreach (self::forCourse($courseId) as $f) {
            if ($f['key'] === $key) {
                return $f;
            }
        }
        return null;
    }

    public static function canStartTopic(int $courseId, array $forum, int $userId): bool
    {
        if (!Access::inClassroom($courseId, $userId)) {
            return false;
        }
        return $forum['type'] !== 'announcements' || Access::canTeach($courseId, $userId);
    }

    public static function canReply(int $courseId, array $forum, int $userId): bool
    {
        return self::canStartTopic($courseId, $forum, $userId);
    }

    /** In a post-first forum, has the learner replied in (or started) this topic? */
    public static function hasPosted(int $topicId, int $userId): bool
    {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT 1 FROM ' . self::table() . ' WHERE (id = %d OR parent_id = %d) AND user_id = %d LIMIT 1',
            $topicId,
            $topicId,
            $userId
        ));
    }

    /**
     * Topics in a forum, pinned first then by latest activity.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function topics(int $courseId, string $forumKey, string $search, int $perPage, int $page): array
    {
        global $wpdb;
        $t = self::table();
        $where = 'course_id = %d AND forum = %s AND parent_id = 0';
        $params = [$courseId, $forumKey];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (title LIKE %s OR body LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$where}", $params)); // phpcs:ignore WordPress.DB
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT * FROM {$t} WHERE {$where} ORDER BY pinned DESC, last_activity DESC LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, max(0, ($page - 1) * $perPage)])
        ), ARRAY_A) ?: [];
        return ['rows' => array_map([self::class, 'hydrate'], $rows), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function replies(int $topicId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE parent_id = %d ORDER BY created ASC, id ASC', $topicId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** @return array<string,mixed> */
    public static function create(int $courseId, string $forumKey, int $parentId, int $userId, string $title, string $body): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(self::table(), [ // phpcs:ignore WordPress.DB
            'course_id'     => $courseId,
            'forum'         => $forumKey,
            'parent_id'     => $parentId,
            'user_id'       => $userId,
            'title'         => $parentId > 0 ? '' : mb_substr($title, 0, 200),
            'body'          => $body,
            'pinned'        => 0,
            'replies'       => 0,
            'last_activity' => $now,
            'created'       => $now,
            'updated'       => $now,
        ]);
        $id = (int)$wpdb->insert_id;
        if ($parentId > 0) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET replies = replies + 1, last_activity = %s WHERE id = %d', $now, $parentId)); // phpcs:ignore WordPress.DB
        }
        return self::find($id) ?? [];
    }

    public static function update(int $id, string $title, string $body): void
    {
        global $wpdb;
        $data = ['body' => $body, 'updated' => current_time('mysql', true)];
        $row = self::find($id);
        if ($row !== null && $row['parent_id'] === 0 && $title !== '') {
            $data['title'] = mb_substr($title, 0, 200);
        }
        $wpdb->update(self::table(), $data, ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $row = self::find($id);
        if ($row === null) {
            return;
        }
        if ($row['parent_id'] === 0) {
            $wpdb->delete(self::table(), ['parent_id' => $id]); // phpcs:ignore WordPress.DB
        } else {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET replies = GREATEST(replies - 1, 0) WHERE id = %d', $row['parent_id'])); // phpcs:ignore WordPress.DB
        }
        $wpdb->delete(self::table(), ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    public static function setPinned(int $id, bool $pinned): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['pinned' => $pinned ? 1 : 0], ['id' => $id, 'parent_id' => 0]); // phpcs:ignore WordPress.DB
    }

    public static function deleteForCourse(int $courseId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['course_id' => $courseId]); // phpcs:ignore WordPress.DB
    }

    /** Anonymise a user's posts (keeps threads readable). */
    public static function anonymiseUser(int $userId): int
    {
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET user_id = 0, body = %s WHERE user_id = %d", __('[removed at the author’s request]', 'sepo-lms'), $userId)); // phpcs:ignore WordPress.DB
    }

    /** @return list<array<string,mixed>> for the privacy exporter */
    public static function byUser(int $userId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created ASC', $userId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** Plain text to safe HTML: escaped, links clickable, line breaks kept. */
    public static function format(string $body): string
    {
        return wpautop(make_clickable(esc_html($body)));
    }

    /** Queue the announcement email so posting stays fast. */
    public static function queueAnnouncement(int $postId): void
    {
        wp_schedule_single_event(time() + 5, self::MAIL_HOOK, [$postId]);
    }

    /** WP-cron: email an announcement to everyone with access to the course. */
    public static function sendAnnouncement(int $postId): void
    {
        $post = self::find($postId);
        if ($post === null || $post['parent_id'] !== 0) {
            return;
        }
        $forum = self::forum($post['course_id'], $post['forum']);
        if ($forum === null || !$forum['email']) {
            return;
        }
        $course = get_the_title($post['course_id']);
        $url = add_query_arg(['slms_forum' => $post['forum'], 'slms_topic' => $post['id']], (string)get_permalink($post['course_id'])) . '#forums';
        $subject = sprintf('[%s] %s', $course, $post['title']);
        $html = '<p style="color:#6b7280;margin:0 0 6px">' . esc_html($course) . ' · ' . esc_html($forum['title']) . '</p>'
            . '<h2 style="margin:0 0 12px">' . esc_html($post['title']) . '</h2>'
            . self::format($post['body'])
            . '<p><a href="' . esc_url($url) . '">' . esc_html__('Open in the course', 'sepo-lms') . '</a></p>';
        foreach (Enrollments::learners($post['course_id']) as $learner) {
            if ($learner['user_id'] === $post['user_id']) {
                continue;
            }
            Mailer::send((string)$learner['email'], $subject, $html);
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function hydrate(array $r): array
    {
        foreach (['id', 'course_id', 'parent_id', 'user_id', 'replies'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        $r['pinned'] = !empty($r['pinned']);
        return $r;
    }
}
