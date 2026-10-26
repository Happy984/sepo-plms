<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Class attendance.
 *
 * Staff create sessions for a course (a title, start and end time). When a
 * session allows self check-in, learners check in from the course page
 * while it is open, entering the session code if it has one; checking in
 * more than "late after" minutes past the start marks them late. Staff can
 * set or change anyone's status at any time: present, late, absent or
 * excused. Learners with no record after a session ends count as absent.
 *
 * Times are stored in UTC.
 */
final class Attendance
{
    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    /** Minutes before the start when self check-in opens. */
    private const EARLY_MINUTES = 15;

    public static function sessionTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_att_sessions';
    }

    public static function recordTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_att_records';
    }

    /** @return array<string,string> */
    public static function statusLabels(): array
    {
        return [
            'present' => __('Present', 'sepo-lms'),
            'late'    => __('Late', 'sepo-lms'),
            'absent'  => __('Absent', 'sepo-lms'),
            'excused' => __('Excused', 'sepo-lms'),
        ];
    }

    /** @return list<array<string,mixed>> newest first */
    public static function sessions(int $courseId): array
    {
        global $wpdb;
        $s = self::sessionTable();
        $r = self::recordTable();
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT s.*, (SELECT COUNT(*) FROM {$r} r WHERE r.session_id = s.id AND r.status IN ('present','late')) AS attended
             FROM {$s} s WHERE s.course_id = %d ORDER BY s.starts DESC",
            $courseId
        ), ARRAY_A) ?: [];
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function session(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::sessionTable() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Create or update a session from form input (site-time datetime-local values).
     *
     * @param array<string,mixed> $in title, starts, ends, late_after, self_checkin, use_code
     * @throws \DomainException
     */
    public static function save(int $courseId, array $in, int $userId, int $id = 0): int
    {
        $title = sanitize_text_field((string)($in['title'] ?? ''));
        $starts = self::toGmt((string)($in['starts'] ?? ''));
        $ends = self::toGmt((string)($in['ends'] ?? ''));
        if ($starts === '' || $ends === '') {
            throw new \DomainException(__('Enter a start and an end time.', 'sepo-lms'));
        }
        if (strtotime($ends . ' UTC') <= strtotime($starts . ' UTC')) {
            throw new \DomainException(__('The session must end after it starts.', 'sepo-lms'));
        }
        if ($title === '') {
            $title = sprintf(/* translators: %s: date */ __('Class on %s', 'sepo-lms'), get_date_from_gmt($starts, (string)get_option('date_format')));
        }
        $data = [
            'title'        => mb_substr($title, 0, 200),
            'starts'       => $starts,
            'ends'         => $ends,
            'late_after'   => max(0, min(600, (int)($in['late_after'] ?? 10))),
            'self_checkin' => empty($in['self_checkin']) ? 0 : 1,
        ];
        global $wpdb;
        if ($id > 0) {
            $existing = self::session($id);
            if ($existing === null) {
                throw new \DomainException(__('Session not found.', 'sepo-lms'));
            }
            if (!empty($in['use_code']) && $existing['code'] === '') {
                $data['code'] = self::newCode();
            } elseif (empty($in['use_code'])) {
                $data['code'] = '';
            }
            $wpdb->update(self::sessionTable(), $data, ['id' => $id]); // phpcs:ignore WordPress.DB
            return $id;
        }
        $data += [
            'course_id'  => $courseId,
            'code'       => !empty($in['use_code']) ? self::newCode() : '',
            'created_by' => $userId,
            'created'    => current_time('mysql', true),
        ];
        $wpdb->insert(self::sessionTable(), $data); // phpcs:ignore WordPress.DB
        return (int)$wpdb->insert_id;
    }

    public static function newCodeFor(int $sessionId): string
    {
        $code = self::newCode();
        global $wpdb;
        $wpdb->update(self::sessionTable(), ['code' => $code], ['id' => $sessionId]); // phpcs:ignore WordPress.DB
        return $code;
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::recordTable(), ['session_id' => $id]); // phpcs:ignore WordPress.DB
        $wpdb->delete(self::sessionTable(), ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    /** Is self check-in open for this session right now? */
    public static function isOpen(array $session, ?int $now = null): bool
    {
        $now ??= time();
        return $session['self_checkin']
            && $now >= $session['starts_ts'] - self::EARLY_MINUTES * MINUTE_IN_SECONDS
            && $now <= $session['ends_ts'];
    }

    /**
     * A learner checks themself in.
     *
     * @return array<string,mixed> the record
     * @throws \DomainException
     */
    public static function checkIn(int $sessionId, int $userId, string $code): array
    {
        $session = self::session($sessionId);
        if ($session === null || !Access::inClassroom($session['course_id'], $userId)) {
            throw new \DomainException(__('Session not found.', 'sepo-lms'), 404);
        }
        if (!self::isOpen($session)) {
            throw new \DomainException(__('Check-in is not open for this session.', 'sepo-lms'), 409);
        }
        if ($session['code'] !== '' && !hash_equals(strtoupper($session['code']), strtoupper(trim($code)))) {
            throw new \DomainException(__('That code is not right. Ask your teacher for the session code.', 'sepo-lms'), 422);
        }
        $existing = self::records($sessionId)[$userId] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        $late = time() > $session['starts_ts'] + $session['late_after'] * MINUTE_IN_SECONDS;
        self::mark($sessionId, $userId, $late ? 'late' : 'present', '', $userId, 'self');
        return self::records($sessionId)[$userId] ?? [];
    }

    public static function mark(int $sessionId, int $userId, string $status, string $note, int $by, string $method = 'teacher'): void
    {
        global $wpdb;
        if ($status === '') {
            $wpdb->delete(self::recordTable(), ['session_id' => $sessionId, 'user_id' => $userId]); // phpcs:ignore WordPress.DB
            return;
        }
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'INSERT INTO ' . self::recordTable() . ' (session_id, user_id, status, method, note, marked_by, marked_at) VALUES (%d, %d, %s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status), method = VALUES(method), note = VALUES(note), marked_by = VALUES(marked_by), marked_at = VALUES(marked_at)',
            $sessionId,
            $userId,
            $status,
            $method === 'self' ? 'self' : 'teacher',
            mb_substr($note, 0, 255),
            $by,
            current_time('mysql', true)
        ));
    }

    /** @return array<int,array<string,mixed>> keyed by user ID */
    public static function records(int $sessionId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::recordTable() . ' WHERE session_id = %d', $sessionId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['user_id']] = [
                'status'    => (string)$r['status'],
                'method'    => (string)$r['method'],
                'note'      => (string)$r['note'],
                'marked_at' => (string)$r['marked_at'],
            ];
        }
        return $out;
    }

    /**
     * Every session in a course with this learner's status, and a summary.
     *
     * @return array{sessions:list<array<string,mixed>>,counts:array<string,int>,rate:?float}
     */
    public static function forLearner(int $courseId, int $userId): array
    {
        global $wpdb;
        $s = self::sessionTable();
        $r = self::recordTable();
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT s.*, r.status AS my_status, r.method AS my_method FROM {$s} s LEFT JOIN {$r} r ON r.session_id = s.id AND r.user_id = %d WHERE s.course_id = %d ORDER BY s.starts DESC",
            $userId,
            $courseId
        ), ARRAY_A) ?: [];
        $counts = array_fill_keys(self::STATUSES, 0);
        $sessions = [];
        $now = time();
        foreach ($rows as $row) {
            $sess = self::hydrate($row);
            $status = (string)($row['my_status'] ?? '');
            if ($status === '' && $sess['ends_ts'] < $now) {
                $status = 'absent';
            }
            if ($status !== '') {
                $counts[$status]++;
            }
            $sess['my_status'] = $status;
            $sess['open'] = self::isOpen($sess, $now);
            unset($sess['code']);
            $sessions[] = $sess;
        }
        $counted = $counts['present'] + $counts['late'] + $counts['absent'];
        $rate = $counted > 0 ? round(($counts['present'] + $counts['late']) / $counted * 100, 1) : null;
        return ['sessions' => $sessions, 'counts' => $counts, 'rate' => $rate];
    }

    /**
     * Learners × sessions for a course, oldest session first.
     *
     * @return array{learners:list<array<string,mixed>>,sessions:list<array<string,mixed>>,grid:array<int,array<int,string>>}
     */
    public static function report(int $courseId): array
    {
        $sessions = array_reverse(self::sessions($courseId));
        $learners = Enrollments::learners($courseId);
        $grid = [];
        $now = time();
        foreach ($sessions as $sess) {
            $records = self::records($sess['id']);
            foreach ($learners as $l) {
                $st = $records[$l['user_id']]['status'] ?? '';
                if ($st === '' && $sess['ends_ts'] < $now) {
                    $st = 'absent';
                }
                $grid[$l['user_id']][$sess['id']] = $st;
            }
        }
        return ['learners' => $learners, 'sessions' => $sessions, 'grid' => $grid];
    }

    public static function deleteForCourse(int $courseId): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::sessionTable() . ' WHERE course_id = %d', $courseId)) ?: []; // phpcs:ignore WordPress.DB
        foreach ($ids as $id) {
            self::delete((int)$id);
        }
    }

    public static function deleteForUser(int $userId): int
    {
        global $wpdb;
        return (int)$wpdb->delete(self::recordTable(), ['user_id' => $userId]); // phpcs:ignore WordPress.DB
    }

    /** @return list<array<string,mixed>> for the privacy exporter */
    public static function byUser(int $userId): array
    {
        global $wpdb;
        $s = self::sessionTable();
        $r = self::recordTable();
        return $wpdb->get_results($wpdb->prepare("SELECT s.course_id, s.title, s.starts, r.status, r.marked_at FROM {$r} r JOIN {$s} s ON s.id = r.session_id WHERE r.user_id = %d", $userId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
    }

    /** Site-time "Y-m-d\TH:i" (or "Y-m-d H:i") to UTC MySQL datetime, or ''. */
    public static function toGmt(string $local): string
    {
        $local = str_replace('T', ' ', trim($local));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local)) {
            return '';
        }
        return get_gmt_from_date(strlen($local) === 16 ? $local . ':00' : $local);
    }

    /** UTC MySQL datetime to a site-time datetime-local value. */
    public static function toLocalInput(string $gmt): string
    {
        return $gmt !== '' ? get_date_from_gmt($gmt, 'Y-m-d\TH:i') : '';
    }

    private static function newCode(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function hydrate(array $r): array
    {
        return [
            'id'           => (int)$r['id'],
            'course_id'    => (int)$r['course_id'],
            'title'        => (string)$r['title'],
            'starts'       => (string)$r['starts'],
            'ends'         => (string)$r['ends'],
            'starts_ts'    => (int)strtotime($r['starts'] . ' UTC'),
            'ends_ts'      => (int)strtotime($r['ends'] . ' UTC'),
            'late_after'   => (int)$r['late_after'],
            'code'         => (string)$r['code'],
            'self_checkin' => !empty($r['self_checkin']),
            'attended'     => (int)($r['attended'] ?? 0),
        ];
    }
}
