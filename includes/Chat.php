<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Course chat.
 *
 * Rooms:
 *   c:{course}           the course room: everyone in the course
 *   d:{course}:{a}:{b}   a direct conversation between two users (a < b)
 *
 * A learner can message course staff directly; learner-to-learner messages
 * are allowed only when the site turns them on. The browser checks for new
 * messages every few seconds while a chat is open. Staff can remove any
 * message; authors can remove their own.
 */
final class Chat
{
    private const MAX_LENGTH = 2000;
    private const PER_MINUTE = 20;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_chat';
    }

    public static function readsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_chat_reads';
    }

    public static function courseRoom(int $courseId): string
    {
        return 'c:' . $courseId;
    }

    public static function directRoom(int $courseId, int $a, int $b): string
    {
        return 'd:' . $courseId . ':' . min($a, $b) . ':' . max($a, $b);
    }

    /** @return array{type:string,course:int,users:list<int>}|null */
    public static function parse(string $room): ?array
    {
        if (preg_match('/^c:(\d+)$/', $room, $m)) {
            return ['type' => 'course', 'course' => (int)$m[1], 'users' => []];
        }
        if (preg_match('/^d:(\d+):(\d+):(\d+)$/', $room, $m) && (int)$m[2] < (int)$m[3]) {
            return ['type' => 'direct', 'course' => (int)$m[1], 'users' => [(int)$m[2], (int)$m[3]]];
        }
        return null;
    }

    public static function enabled(): bool
    {
        return Settings::feature('chat');
    }

    public static function canAccess(string $room, int $userId): bool
    {
        $p = self::parse($room);
        if ($p === null || !self::enabled() || !Access::inClassroom($p['course'], $userId)) {
            return false;
        }
        if ($p['type'] === 'course') {
            return true;
        }
        if (!in_array($userId, $p['users'], true)) {
            return false;
        }
        $other = $p['users'][0] === $userId ? $p['users'][1] : $p['users'][0];
        return self::canMessage($p['course'], $userId, $other);
    }

    /** May $from start or continue a direct conversation with $to? */
    public static function canMessage(int $courseId, int $from, int $to): bool
    {
        if ($from === $to || !Access::inClassroom($courseId, $to)) {
            return false;
        }
        if (Access::canTeach($courseId, $from) || Access::canTeach($courseId, $to)) {
            return true;
        }
        return !empty(Settings::get('chat_learner_dm'));
    }

    /**
     * People this user can message directly in the course.
     *
     * @return list<array{id:int,name:string,staff:bool}>
     */
    public static function contacts(int $courseId, int $userId): array
    {
        $out = [];
        $isStaff = Access::canTeach($courseId, $userId);
        foreach (Access::staffUsers($courseId) as $uid) {
            if ($uid !== $userId) {
                $u = get_userdata($uid);
                if ($u) {
                    $out[$uid] = ['id' => $uid, 'name' => (string)$u->display_name, 'staff' => true];
                }
            }
        }
        if ($isStaff || !empty(Settings::get('chat_learner_dm'))) {
            foreach (Enrollments::learners($courseId) as $l) {
                if ($l['user_id'] !== $userId && !isset($out[$l['user_id']])) {
                    $out[$l['user_id']] = ['id' => $l['user_id'], 'name' => $l['name'], 'staff' => false];
                }
            }
        }
        return array_values($out);
    }

    /**
     * The user's rooms in a course with unread counts: the course room
     * first, then direct conversations by latest message.
     *
     * @return list<array{room:string,title:string,unread:int,last:int,staff:bool}>
     */
    public static function rooms(int $courseId, int $userId): array
    {
        global $wpdb;
        $t = self::table();
        $r = self::readsTable();
        $like = $wpdb->esc_like('d:' . $courseId . ':') . '%';
        $direct = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT room, MAX(id) AS last FROM {$t} WHERE course_id = %d AND room LIKE %s AND (room LIKE %s OR room LIKE %s) GROUP BY room ORDER BY last DESC",
            $courseId,
            $like,
            '%:' . $userId . ':%',
            '%:' . $userId
        ), ARRAY_A) ?: [];

        $rooms = [['room' => self::courseRoom($courseId), 'last' => 0]];
        foreach ($direct as $d) {
            $p = self::parse((string)$d['room']);
            if ($p !== null && in_array($userId, $p['users'], true)) {
                $rooms[] = ['room' => (string)$d['room'], 'last' => (int)$d['last']];
            }
        }

        $out = [];
        foreach ($rooms as $room) {
            $p = self::parse($room['room']);
            if ($p === null) {
                continue;
            }
            $lastRead = (int)$wpdb->get_var($wpdb->prepare("SELECT last_id FROM {$r} WHERE user_id = %d AND room = %s", $userId, $room['room'])); // phpcs:ignore WordPress.DB
            $unread = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE room = %s AND id > %d AND user_id <> %d AND deleted = 0", $room['room'], $lastRead, $userId)); // phpcs:ignore WordPress.DB
            if ($p['type'] === 'course') {
                $title = __('Course room', 'sepo-lms');
                $staff = false;
            } else {
                $other = $p['users'][0] === $userId ? $p['users'][1] : $p['users'][0];
                $u = get_userdata($other);
                $title = $u ? (string)$u->display_name : __('Former member', 'sepo-lms');
                $staff = Access::canTeach($courseId, $other);
            }
            $out[] = ['room' => $room['room'], 'title' => $title, 'unread' => $unread, 'last' => $room['last'], 'staff' => $staff];
        }
        return $out;
    }

    /** Total unread messages for a user across a course's rooms. */
    public static function unreadTotal(int $courseId, int $userId): int
    {
        return (int)array_sum(array_column(self::rooms($courseId, $userId), 'unread'));
    }

    /**
     * Messages after $after (newest when 0), or before $before for history.
     *
     * @return list<array<string,mixed>>
     */
    public static function messages(string $room, int $courseId, int $viewer, int $after = 0, int $before = 0, int $limit = 50): array
    {
        global $wpdb;
        $t = self::table();
        if ($after > 0) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE room = %s AND id > %d ORDER BY id ASC LIMIT %d", $room, $after, $limit), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        } else {
            $sql = $before > 0
                ? $wpdb->prepare("SELECT * FROM {$t} WHERE room = %s AND id < %d ORDER BY id DESC LIMIT %d", $room, $before, $limit)
                : $wpdb->prepare("SELECT * FROM {$t} WHERE room = %s ORDER BY id DESC LIMIT %d", $room, $limit);
            $rows = array_reverse($wpdb->get_results($sql, ARRAY_A) ?: []); // phpcs:ignore WordPress.DB
        }
        $isStaff = Access::canTeach($courseId, $viewer);
        $names = [];
        $out = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if (!isset($names[$uid])) {
                $u = $uid > 0 ? get_userdata($uid) : false;
                $names[$uid] = [
                    'name'  => $u ? (string)$u->display_name : __('Former member', 'sepo-lms'),
                    'staff' => $uid > 0 && Access::canTeach($courseId, $uid),
                ];
            }
            $deleted = !empty($r['deleted']);
            $out[] = [
                'id'        => (int)$r['id'],
                'user'      => $uid,
                'name'      => $names[$uid]['name'],
                'staff'     => $names[$uid]['staff'],
                'mine'      => $uid === $viewer,
                'deleted'   => $deleted,
                'html'      => $deleted ? '' : make_clickable(nl2br(esc_html((string)$r['body']))),
                'time'      => (int)strtotime($r['created'] . ' UTC'),
                'can_delete'=> !$deleted && ($uid === $viewer || $isStaff),
            ];
        }
        return $out;
    }

    /**
     * @return array<string,mixed> the stored message
     * @throws \DomainException
     */
    public static function send(string $room, int $courseId, int $userId, string $body): array
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '') {
            throw new \DomainException(__('Type a message first.', 'sepo-lms'), 422);
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new \DomainException(sprintf(/* translators: %d: characters */ __('Messages can be up to %d characters.', 'sepo-lms'), self::MAX_LENGTH), 422);
        }
        $key = 'slms_chat_rate_' . $userId;
        $sent = (int)get_transient($key);
        if ($sent >= self::PER_MINUTE) {
            throw new \DomainException(__('You are sending messages too quickly. Wait a moment.', 'sepo-lms'), 429);
        }
        set_transient($key, $sent + 1, MINUTE_IN_SECONDS);

        global $wpdb;
        $wpdb->insert(self::table(), [ // phpcs:ignore WordPress.DB
            'room'      => $room,
            'course_id' => $courseId,
            'user_id'   => $userId,
            'body'      => $body,
            'deleted'   => 0,
            'created'   => current_time('mysql', true),
        ]);
        $id = (int)$wpdb->insert_id;
        self::markRead($userId, $room, $id);
        $msgs = self::messages($room, $courseId, $userId, $id - 1, 0, 1);
        return $msgs[0] ?? ['id' => $id];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? $row : null;
    }

    public static function remove(int $id): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['deleted' => 1, 'body' => ''], ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    public static function markRead(int $userId, string $room, int $lastId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'INSERT INTO ' . self::readsTable() . ' (user_id, room, last_id) VALUES (%d, %s, %d) ON DUPLICATE KEY UPDATE last_id = GREATEST(last_id, VALUES(last_id))',
            $userId,
            $room,
            $lastId
        ));
    }

    public static function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE created < %s', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB
    }

    public static function deleteForCourse(int $courseId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['course_id' => $courseId]); // phpcs:ignore WordPress.DB
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::readsTable() . ' WHERE room = %s OR room LIKE %s', self::courseRoom($courseId), $wpdb->esc_like('d:' . $courseId . ':') . '%')); // phpcs:ignore WordPress.DB
    }

    public static function deleteForUser(int $userId): int
    {
        global $wpdb;
        $n = (int)$wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET deleted = 1, body = '' WHERE user_id = %d", $userId)); // phpcs:ignore WordPress.DB
        $wpdb->delete(self::readsTable(), ['user_id' => $userId]); // phpcs:ignore WordPress.DB
        return $n;
    }

    /** @return list<array<string,mixed>> for the privacy exporter */
    public static function byUser(int $userId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT room, course_id, body, created FROM ' . self::table() . ' WHERE user_id = %d AND deleted = 0 ORDER BY id ASC', $userId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
    }
}
