<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Saved quiz results: one row per learner per quiz, holding the latest
 * attempt, how many attempts there have been, and the best score.
 *
 * Guests (quizzes that do not require sign-in) are kept apart by a token
 * built from the email address they give, or a random one.
 */
final class QuizResults
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_quiz_results';
    }

    /** @return array<string,mixed>|null */
    public static function get(int $quizId, int $userId, string $guest = ''): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT * FROM ' . self::table() . ' WHERE quiz_id = %d AND user_id = %d AND guest = %s',
            $quizId,
            $userId,
            $guest
        ), ARRAY_A);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<int,array<string,mixed>> quiz id => result */
    public static function forUser(int $userId): array
    {
        global $wpdb;
        if ($userId <= 0) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE user_id = %d AND guest = '' ORDER BY submitted_at DESC", $userId), ARRAY_A); // phpcs:ignore WordPress.DB
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $r = self::hydrate($r);
            $out[$r['quiz_id']] = $r;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $result from Quiz::score()
     * @param array<int|string,mixed> $answers
     * @return array<string,mixed> the saved row
     */
    public static function save(int $quizId, int $courseId, int $userId, string $guest, string $email, array $result, array $answers): array
    {
        global $wpdb;
        $prev = self::get($quizId, $userId, $guest);
        $attempt = (int)($prev['attempt'] ?? 0) + 1;
        $best = max((int)($prev['best_score'] ?? 0), (int)$result['score']);

        $data = [
            'quiz_id'         => $quizId,
            'course_id'       => $courseId,
            'user_id'         => $userId,
            'guest'           => $guest,
            'email'           => mb_substr($email, 0, 160),
            'score'           => (int)$result['score'],
            'passed'          => $result['passed'] ? 1 : 0,
            'total_questions' => (int)$result['total'],
            'answered'        => (int)$result['answered'],
            'correct'         => (int)$result['correct'],
            'points_earned'   => (float)$result['points_earned'],
            'points_total'    => (float)$result['points_total'],
            'weight'          => (float)($result['weight'] ?? 0),
            'answers'         => wp_json_encode($answers),
            'results'         => wp_json_encode($result['results']),
            'attempt'         => $attempt,
            'best_score'      => $best,
            'submitted_at'    => current_time('mysql', true),
        ];

        if ($prev !== null) {
            $wpdb->update(self::table(), $data, ['id' => $prev['id']]); // phpcs:ignore WordPress.DB
            $id = (int)$prev['id'];
        } else {
            $wpdb->insert(self::table(), $data); // phpcs:ignore WordPress.DB
            $id = (int)$wpdb->insert_id;
        }
        return self::find($id) ?? [];
    }

    /**
     * Admin listing.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(int $quizId, int $courseId, string $search, int $perPage, int $page, string $orderby = 'submitted_at', string $order = 'DESC'): array
    {
        global $wpdb;
        $t = self::table();
        $where = ['1=1'];
        $params = [];
        if ($quizId > 0) {
            $where[] = 'r.quiz_id = %d';
            $params[] = $quizId;
        }
        if ($courseId > 0) {
            $where[] = 'r.course_id = %d';
            $params[] = $courseId;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR r.email LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        $orderby = in_array($orderby, ['submitted_at', 'score', 'attempt'], true) ? $orderby : 'submitted_at';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $w = implode(' AND ', $where);
        $from = "{$t} r LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id";

        $count = "SELECT COUNT(*) FROM {$from} WHERE {$w}";
        $total = (int)($params === [] ? $wpdb->get_var($count) : $wpdb->get_var($wpdb->prepare($count, $params))); // phpcs:ignore WordPress.DB

        $sql = "SELECT r.*, u.display_name, u.user_email FROM {$from} WHERE {$w} ORDER BY r.{$orderby} {$order}, r.id DESC LIMIT %d OFFSET %d";
        $params2 = array_merge($params, [$perPage, max(0, ($page - 1) * $perPage)]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params2), ARRAY_A); // phpcs:ignore WordPress.DB

        return ['rows' => array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []), 'total' => $total];
    }

    /** @return array<string,float|int> */
    public static function quizStats(int $quizId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT COUNT(*) AS learners, SUM(attempt) AS attempts, AVG(score) AS avg_score, SUM(passed) AS passed, MAX(score) AS hi, MIN(score) AS lo FROM ' . self::table() . ' WHERE quiz_id = %d',
            $quizId
        ), ARRAY_A);
        $n = (int)($row['learners'] ?? 0);
        return [
            'learners'      => $n,
            'attempts'      => (int)($row['attempts'] ?? 0),
            'average_score' => round((float)($row['avg_score'] ?? 0), 1),
            'pass_rate'     => $n > 0 ? round((int)$row['passed'] / $n * 100, 1) : 0,
            'highest_score' => (int)($row['hi'] ?? 0),
            'lowest_score'  => (int)($row['lo'] ?? 0),
        ];
    }

    /** @return array<string,float|int> */
    public static function userStats(int $userId): array
    {
        $rows = self::forUser($userId);
        $n = count($rows);
        if ($n === 0) {
            return ['total_quizzes' => 0, 'average_score' => 0, 'quizzes_passed' => 0, 'pass_rate' => 0, 'highest_score' => 0, 'lowest_score' => 0];
        }
        $scores = array_map(static fn($r) => (int)$r['score'], $rows);
        $passed = count(array_filter($rows, static fn($r) => $r['passed']));
        return [
            'total_quizzes'  => $n,
            'average_score'  => round(array_sum($scores) / $n, 1),
            'quizzes_passed' => $passed,
            'pass_rate'      => round($passed / $n * 100, 1),
            'highest_score'  => max($scores),
            'lowest_score'   => min($scores),
        ];
    }

    /** Delete a result so the learner can take the quiz again; the quiz is no longer "done". */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $r = self::find($id);
        if ($r === null) {
            return false;
        }
        $ok = (bool)$wpdb->delete(self::table(), ['id' => $id]); // phpcs:ignore WordPress.DB
        if ($ok && $r['user_id'] > 0 && $r['course_id'] > 0) {
            Progress::set($r['course_id'], $r['user_id'], $r['quiz_id'], false);
        }
        return $ok;
    }

    public static function deleteForQuiz(int $quizId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['quiz_id' => $quizId]); // phpcs:ignore WordPress.DB
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function hydrate(array $r): array
    {
        foreach (['id', 'quiz_id', 'course_id', 'user_id', 'score', 'total_questions', 'answered', 'correct', 'attempt', 'best_score'] as $k) {
            $r[$k] = (int)($r[$k] ?? 0);
        }
        $r['passed'] = !empty($r['passed']);
        foreach (['points_earned', 'points_total', 'weight'] as $k) {
            $r[$k] = (float)($r[$k] ?? 0);
        }
        $a = json_decode((string)($r['answers'] ?? ''), true);
        $res = json_decode((string)($r['results'] ?? ''), true);
        $r['answers'] = is_array($a) ? $a : [];
        $r['results'] = is_array($res) ? $res : [];
        return $r;
    }
}
