<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Assignment submissions: one row per learner per assignment.
 *
 * Status:
 *   submitted  handed in, waiting for a grade
 *   graded     marked; score is the final percentage after any late penalty
 *   returned   sent back for rework; the learner can hand in again
 *
 * Handing in again replaces the previous files and text and counts as a
 * new attempt.
 */
final class Submissions
{
    /** @var array<int,array<int,array<string,mixed>>> */
    private static array $byUser = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_submissions';
    }

    /** @return array<string,mixed>|null */
    public static function get(int $assignmentId, int $userId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE assignment_id = %d AND user_id = %d', $assignmentId, $userId), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<int,array<string,mixed>> keyed by assignment ID */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (!isset(self::$byUser[$userId])) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id = %d', $userId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
            self::$byUser[$userId] = [];
            foreach ($rows as $r) {
                self::$byUser[$userId][(int)$r['assignment_id']] = self::hydrate($r);
            }
        }
        return self::$byUser[$userId];
    }

    /**
     * Store a hand-in, replacing any earlier one.
     *
     * @param list<array{name:string,path:string,size:int,mime:string}> $files
     * @return array<string,mixed>
     */
    public static function submit(int $assignmentId, int $courseId, int $userId, string $body, array $files, bool $late, int $daysLate, int $proctorId): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $old = self::get($assignmentId, $userId);
        $data = [
            'assignment_id' => $assignmentId,
            'course_id'     => $courseId,
            'user_id'       => $userId,
            'status'        => 'submitted',
            'body'          => $body,
            'files'         => (string)wp_json_encode($files),
            'late'          => $late ? 1 : 0,
            'late_days'     => $daysLate,
            'score'         => null,
            'points'        => null,
            'graded_by'     => 0,
            'graded_at'     => null,
            'proctor_id'    => $proctorId,
            'submitted_at'  => $now,
            'updated'       => $now,
        ];
        if ($old === null) {
            $data['attempt'] = 1;
            $data['feedback'] = '';
            $wpdb->insert(self::table(), $data); // phpcs:ignore WordPress.DB
            $id = (int)$wpdb->insert_id;
        } else {
            foreach ($old['files'] as $f) {
                Storage::delete((string)$f['path']);
            }
            $data['attempt'] = (int)$old['attempt'] + 1;
            $wpdb->update(self::table(), $data, ['id' => $old['id']]); // phpcs:ignore WordPress.DB
            $id = (int)$old['id'];
        }
        self::$byUser = [];
        $saved = self::find($id) ?? [];

        /**
         * Fires after a learner hands in an assignment.
         *
         * @param array<string,mixed> $saved The stored submission.
         */
        do_action('sepo_lms_assignment_submitted', $saved);
        return $saved;
    }

    /**
     * Record a quiz-type assignment's result as a graded submission.
     *
     * @return array<string,mixed>
     */
    public static function recordQuiz(int $assignmentId, int $courseId, int $userId, float $quizPercent, float $finalPercent, bool $late, int $daysLate, int $proctorId, float $maxPoints): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $old = self::get($assignmentId, $userId);
        $data = [
            'assignment_id' => $assignmentId,
            'course_id'     => $courseId,
            'user_id'       => $userId,
            'status'        => 'graded',
            'body'          => '',
            'files'         => '[]',
            'late'          => $late ? 1 : 0,
            'late_days'     => $daysLate,
            'score'         => $finalPercent,
            'points'        => round($quizPercent / 100 * $maxPoints, 2),
            'graded_by'     => 0,
            'graded_at'     => $now,
            'proctor_id'    => $proctorId,
            'submitted_at'  => $now,
            'updated'       => $now,
        ];
        if ($old === null) {
            $data['attempt'] = 1;
            $data['feedback'] = '';
            $wpdb->insert(self::table(), $data); // phpcs:ignore WordPress.DB
            $id = (int)$wpdb->insert_id;
        } else {
            $data['attempt'] = (int)$old['attempt'] + 1;
            $wpdb->update(self::table(), $data, ['id' => $old['id']]); // phpcs:ignore WordPress.DB
            $id = (int)$old['id'];
        }
        self::$byUser = [];
        return self::find($id) ?? [];
    }

    /**
     * Grade or return a submission.
     *
     * @return array<string,mixed>|null
     */
    public static function grade(int $id, ?float $points, string $feedback, int $graderId, bool $return): ?array
    {
        global $wpdb;
        $row = self::find($id);
        if ($row === null) {
            return null;
        }
        $s = Assignments::settings((int)$row['assignment_id']);
        $now = current_time('mysql', true);
        if ($return) {
            $data = ['status' => 'returned', 'feedback' => $feedback, 'graded_by' => $graderId, 'graded_at' => $now, 'updated' => $now, 'score' => null, 'points' => null];
        } else {
            $points = max(0.0, min($s['points'], (float)$points));
            $raw = $s['points'] > 0 ? $points / $s['points'] * 100 : 0.0;
            $data = [
                'status'    => 'graded',
                'points'    => round($points, 2),
                'score'     => Assignments::applyPenalty($raw, $s, (int)$row['late_days']),
                'feedback'  => $feedback,
                'graded_by' => $graderId,
                'graded_at' => $now,
                'updated'   => $now,
            ];
        }
        $wpdb->update(self::table(), $data, ['id' => $id]); // phpcs:ignore WordPress.DB
        self::$byUser = [];
        $saved = self::find($id);
        if ($saved !== null) {
            if (!$return && $saved['course_id'] > 0) {
                Progress::set((int)$saved['course_id'], (int)$saved['user_id'], (int)$saved['assignment_id'], true);
            }
            if ($return && $saved['course_id'] > 0) {
                Progress::set((int)$saved['course_id'], (int)$saved['user_id'], (int)$saved['assignment_id'], false);
            }

            /**
             * Fires after staff grade or return a submission.
             *
             * @param array<string,mixed> $saved
             * @param bool                $returned
             */
            do_action('sepo_lms_assignment_graded', $saved, $return);
        }
        return $saved;
    }

    /**
     * Staff list with filters: course_ids (allowed courses), assignment, status, search.
     *
     * @param array<string,mixed> $args
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $args, int $perPage = 25, int $page = 1): array
    {
        global $wpdb;
        $t = self::table();
        $where = ['1=1'];
        $params = [];
        $courses = array_map('intval', (array)($args['course_ids'] ?? []));
        if ($courses === []) {
            return ['rows' => [], 'total' => 0];
        }
        $where[] = 's.course_id IN (' . implode(',', array_fill(0, count($courses), '%d')) . ')';
        $params = array_merge($params, $courses);
        if (!empty($args['assignment'])) {
            $where[] = 's.assignment_id = %d';
            $params[] = (int)$args['assignment'];
        }
        if (!empty($args['status'])) {
            if ($args['status'] === 'late') {
                $where[] = 's.late = 1';
            } else {
                $where[] = 's.status = %s';
                $params[] = (string)$args['status'];
            }
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string)$args['search']) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $w = implode(' AND ', $where);
        $from = "FROM {$t} s LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id WHERE {$w}";
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$from}", $params)); // phpcs:ignore WordPress.DB
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT s.*, u.display_name, u.user_email {$from} ORDER BY FIELD(s.status, 'submitted', 'returned', 'graded'), s.submitted_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, max(0, ($page - 1) * $perPage)])
        ), ARRAY_A) ?: [];
        return ['rows' => array_map([self::class, 'hydrate'], $rows), 'total' => $total];
    }

    /** @return array{submitted:int,graded:int,returned:int} for the given courses */
    public static function counts(array $courseIds): array
    {
        $out = ['submitted' => 0, 'graded' => 0, 'returned' => 0];
        if ($courseIds === []) {
            return $out;
        }
        global $wpdb;
        $in = implode(',', array_map('intval', $courseIds));
        foreach ($wpdb->get_results('SELECT status, COUNT(*) n FROM ' . self::table() . " WHERE course_id IN ({$in}) GROUP BY status", ARRAY_A) ?: [] as $r) { // phpcs:ignore WordPress.DB
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int)$r['n'];
            }
        }
        return $out;
    }

    public static function deleteForAssignment(int $assignmentId): void
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT files FROM ' . self::table() . ' WHERE assignment_id = %d', $assignmentId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        foreach ($rows as $r) {
            self::deleteFiles((string)$r['files']);
        }
        $wpdb->delete(self::table(), ['assignment_id' => $assignmentId]); // phpcs:ignore WordPress.DB
    }

    public static function deleteForUser(int $userId): int
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT files FROM ' . self::table() . ' WHERE user_id = %d', $userId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        foreach ($rows as $r) {
            self::deleteFiles((string)$r['files']);
        }
        return (int)$wpdb->delete(self::table(), ['user_id' => $userId]); // phpcs:ignore WordPress.DB
    }

    private static function deleteFiles(string $json): void
    {
        $files = json_decode($json, true);
        foreach (is_array($files) ? $files : [] as $f) {
            if (is_array($f) && !empty($f['path'])) {
                Storage::delete((string)$f['path']);
            }
        }
    }

    /** A signed link to one of a submission's files. */
    public static function fileUrl(array $row, int $index, bool $view = false): string
    {
        return Downloads::url('file', (int)$row['id'], $index, $view);
    }

    /** Human label for a status. */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'graded'   => __('Graded', 'sepo-lms'),
            'returned' => __('Returned for rework', 'sepo-lms'),
            default    => __('Awaiting grade', 'sepo-lms'),
        };
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function hydrate(array $r): array
    {
        $files = json_decode((string)($r['files'] ?? '[]'), true);
        foreach (['id', 'assignment_id', 'course_id', 'user_id', 'attempt', 'late_days', 'graded_by', 'proctor_id'] as $k) {
            $r[$k] = (int)($r[$k] ?? 0);
        }
        $r['late'] = !empty($r['late']);
        $r['score'] = $r['score'] !== null ? (float)$r['score'] : null;
        $r['points'] = $r['points'] !== null ? (float)$r['points'] : null;
        $r['files'] = is_array($files) ? array_values(array_filter($files, 'is_array')) : [];
        $r['body'] = (string)($r['body'] ?? '');
        $r['feedback'] = (string)($r['feedback'] ?? '');
        return $r;
    }
}
