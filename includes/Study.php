<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * A learner's private study tools: a checklist and block-based notes.
 *
 * Checklist items and notes belong to one learner and, optionally, to a
 * course and an outline item (the lesson they were written on). Nobody else
 * can read them, staff included.
 *
 * Notes are stored as a list of blocks:
 *   {id, type, html, checked?}
 * with type one of p, h1, h2, h3, ul, ol, todo, quote, callout, code, divider.
 * Inline formatting is limited to bold, italic, underline, strike, code,
 * highlight and links.
 */
final class Study
{
    public const BLOCK_TYPES = ['p', 'h1', 'h2', 'h3', 'ul', 'ol', 'todo', 'quote', 'callout', 'code', 'divider'];
    private const MAX_BLOCKS = 2000;
    private const MAX_TODOS = 500;

    public static function todoTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_todos';
    }

    public static function noteTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_notes';
    }

    // ------------------------------------------------------------ checklist

    /** @return list<array<string,mixed>> */
    public static function todos(int $userId, int $courseId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT * FROM ' . self::todoTable() . ' WHERE user_id = %d AND course_id = %d ORDER BY done ASC, position ASC, id ASC',
            $userId,
            $courseId
        ), ARRAY_A) ?: [];
        return array_map([self::class, 'todoRow'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function todo(int $id, int $userId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::todoTable() . ' WHERE id = %d AND user_id = %d', $id, $userId), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::todoRow($row) : null;
    }

    /**
     * @return array<string,mixed>
     * @throws \DomainException
     */
    public static function addTodo(int $userId, int $courseId, int $itemId, string $text, string $due): array
    {
        global $wpdb;
        $count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::todoTable() . ' WHERE user_id = %d', $userId)); // phpcs:ignore WordPress.DB
        if ($count >= self::MAX_TODOS) {
            throw new \DomainException(__('Your checklist is full. Delete finished items to add more.', 'sepo-lms'));
        }
        $max = (int)$wpdb->get_var($wpdb->prepare('SELECT MAX(position) FROM ' . self::todoTable() . ' WHERE user_id = %d AND course_id = %d', $userId, $courseId)); // phpcs:ignore WordPress.DB
        $wpdb->insert(self::todoTable(), [ // phpcs:ignore WordPress.DB
            'user_id'   => $userId,
            'course_id' => $courseId,
            'item_id'   => $itemId,
            'text'      => mb_substr($text, 0, 500),
            'done'      => 0,
            'due'       => self::date($due),
            'position'  => $max + 10,
            'created'   => current_time('mysql', true),
        ]);
        return self::todo((int)$wpdb->insert_id, $userId) ?? [];
    }

    /** @param array<string,mixed> $patch text, done, due */
    public static function updateTodo(int $id, int $userId, array $patch): ?array
    {
        $row = self::todo($id, $userId);
        if ($row === null) {
            return null;
        }
        $data = [];
        if (array_key_exists('text', $patch)) {
            $text = sanitize_text_field((string)$patch['text']);
            if ($text !== '') {
                $data['text'] = mb_substr($text, 0, 500);
            }
        }
        if (array_key_exists('done', $patch)) {
            $done = rest_sanitize_boolean($patch['done']);
            $data['done'] = $done ? 1 : 0;
            $data['done_at'] = $done ? current_time('mysql', true) : null;
        }
        if (array_key_exists('due', $patch)) {
            $data['due'] = self::date((string)$patch['due']);
        }
        if ($data !== []) {
            global $wpdb;
            $wpdb->update(self::todoTable(), $data, ['id' => $id, 'user_id' => $userId]); // phpcs:ignore WordPress.DB
        }
        return self::todo($id, $userId);
    }

    /** @param list<int> $ids in the new order */
    public static function reorderTodos(int $userId, array $ids): void
    {
        global $wpdb;
        $pos = 0;
        foreach ($ids as $id) {
            $pos += 10;
            $wpdb->update(self::todoTable(), ['position' => $pos], ['id' => (int)$id, 'user_id' => $userId]); // phpcs:ignore WordPress.DB
        }
    }

    public static function deleteTodo(int $id, int $userId): bool
    {
        global $wpdb;
        return (bool)$wpdb->delete(self::todoTable(), ['id' => $id, 'user_id' => $userId]); // phpcs:ignore WordPress.DB
    }

    public static function clearDone(int $userId, int $courseId): int
    {
        global $wpdb;
        return (int)$wpdb->delete(self::todoTable(), ['user_id' => $userId, 'course_id' => $courseId, 'done' => 1]); // phpcs:ignore WordPress.DB
    }

    private static function date(string $raw): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }

    /** @param array<string,mixed> $r */
    private static function todoRow(array $r): array
    {
        return [
            'id'        => (int)$r['id'],
            'course_id' => (int)$r['course_id'],
            'item_id'   => (int)$r['item_id'],
            'text'      => (string)$r['text'],
            'done'      => !empty($r['done']),
            'due'       => (string)($r['due'] ?? ''),
            'position'  => (int)$r['position'],
        ];
    }

    // ---------------------------------------------------------------- notes

    /**
     * Note list (no content), newest first. $courseId < 0 lists every course.
     *
     * @return list<array<string,mixed>>
     */
    public static function notes(int $userId, int $courseId = -1, string $search = ''): array
    {
        global $wpdb;
        $where = 'user_id = %d';
        $params = [$userId];
        if ($courseId >= 0) {
            $where .= ' AND course_id = %d';
            $params[] = $courseId;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (title LIKE %s OR content LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT id, course_id, item_id, title, icon, excerpt, updated FROM ' . self::noteTable() . " WHERE {$where} ORDER BY updated DESC LIMIT 500",
            $params
        ), ARRAY_A) ?: [];
        return array_map(static fn($r) => [
            'id'        => (int)$r['id'],
            'course_id' => (int)$r['course_id'],
            'item_id'   => (int)$r['item_id'],
            'title'     => (string)$r['title'],
            'icon'      => (string)$r['icon'],
            'excerpt'   => (string)$r['excerpt'],
            'updated'   => (string)$r['updated'],
        ], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function note(int $id, int $userId): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::noteTable() . ' WHERE id = %d AND user_id = %d', $id, $userId), ARRAY_A); // phpcs:ignore WordPress.DB
        if (!is_array($r)) {
            return null;
        }
        $blocks = json_decode((string)$r['content'], true);
        return [
            'id'        => (int)$r['id'],
            'course_id' => (int)$r['course_id'],
            'item_id'   => (int)$r['item_id'],
            'title'     => (string)$r['title'],
            'icon'      => (string)$r['icon'],
            'blocks'    => is_array($blocks) ? $blocks : [],
            'updated'   => (string)$r['updated'],
        ];
    }

    /** @return array<string,mixed> */
    public static function createNote(int $userId, int $courseId, int $itemId, string $title): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $blocks = [['id' => wp_generate_uuid4(), 'type' => 'p', 'html' => '']];
        $wpdb->insert(self::noteTable(), [ // phpcs:ignore WordPress.DB
            'user_id'   => $userId,
            'course_id' => $courseId,
            'item_id'   => $itemId,
            'title'     => mb_substr($title, 0, 200),
            'icon'      => '',
            'content'   => (string)wp_json_encode($blocks),
            'excerpt'   => '',
            'created'   => $now,
            'updated'   => $now,
        ]);
        return self::note((int)$wpdb->insert_id, $userId) ?? [];
    }

    /**
     * Save a note's title, icon and blocks.
     *
     * @param mixed $blocks
     */
    public static function saveNote(int $id, int $userId, ?string $title, ?string $icon, $blocks): ?array
    {
        $note = self::note($id, $userId);
        if ($note === null) {
            return null;
        }
        $data = ['updated' => current_time('mysql', true)];
        if ($title !== null) {
            $data['title'] = mb_substr(sanitize_text_field($title), 0, 200);
        }
        if ($icon !== null) {
            $data['icon'] = mb_substr(wp_strip_all_tags($icon), 0, 8);
        }
        if ($blocks !== null) {
            $clean = self::cleanBlocks(is_array($blocks) ? $blocks : []);
            $data['content'] = (string)wp_json_encode($clean);
            $data['excerpt'] = self::excerpt($clean);
        }
        global $wpdb;
        $wpdb->update(self::noteTable(), $data, ['id' => $id, 'user_id' => $userId]); // phpcs:ignore WordPress.DB
        return self::note($id, $userId);
    }

    public static function deleteNote(int $id, int $userId): bool
    {
        global $wpdb;
        return (bool)$wpdb->delete(self::noteTable(), ['id' => $id, 'user_id' => $userId]); // phpcs:ignore WordPress.DB
    }

    /**
     * @param array<mixed> $blocks
     * @return list<array<string,mixed>>
     */
    public static function cleanBlocks(array $blocks): array
    {
        $allowed = [
            'b'      => [],
            'strong' => [],
            'i'      => [],
            'em'     => [],
            'u'      => [],
            's'      => [],
            'code'   => [],
            'mark'   => [],
            'br'     => [],
            'a'      => ['href' => true, 'rel' => true, 'target' => true],
        ];
        $out = [];
        foreach (array_slice($blocks, 0, self::MAX_BLOCKS) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $type = in_array($b['type'] ?? '', self::BLOCK_TYPES, true) ? (string)$b['type'] : 'p';
            $id = preg_replace('/[^a-zA-Z0-9-]/', '', (string)($b['id'] ?? '')) ?: wp_generate_uuid4();
            $html = $type === 'divider' ? '' : (string)($b['html'] ?? '');
            if ($type === 'code') {
                $html = esc_html(wp_strip_all_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
            } else {
                $html = wp_kses($html, $allowed, ['http', 'https', 'mailto']);
            }
            $block = ['id' => substr($id, 0, 40), 'type' => $type, 'html' => mb_substr($html, 0, 20000)];
            if ($type === 'todo') {
                $block['checked'] = !empty($b['checked']);
            }
            $out[] = $block;
        }
        return $out === [] ? [['id' => wp_generate_uuid4(), 'type' => 'p', 'html' => '']] : $out;
    }

    /** @param list<array<string,mixed>> $blocks */
    private static function excerpt(array $blocks): string
    {
        $text = '';
        foreach ($blocks as $b) {
            $plain = trim(html_entity_decode(wp_strip_all_tags((string)$b['html']), ENT_QUOTES, 'UTF-8'));
            if ($plain !== '') {
                $text .= ($text === '' ? '' : ' ') . $plain;
            }
            if (mb_strlen($text) > 200) {
                break;
            }
        }
        return mb_substr($text, 0, 200);
    }

    // -------------------------------------------------------------- privacy

    public static function deleteForUser(int $userId): int
    {
        global $wpdb;
        $n = (int)$wpdb->delete(self::todoTable(), ['user_id' => $userId]); // phpcs:ignore WordPress.DB
        return $n + (int)$wpdb->delete(self::noteTable(), ['user_id' => $userId]); // phpcs:ignore WordPress.DB
    }
}
