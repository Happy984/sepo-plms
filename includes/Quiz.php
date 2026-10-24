<?php

declare(strict_types=1);

namespace SepoLms;

use WP_Post;

/**
 * A quiz: its settings, its questions and the scorer.
 *
 * Questions are stored as JSON in _slms_questions, written by the visual
 * builder on the quiz edit screen. Each question:
 *
 *   id          stable number, so saved answers still match after reordering
 *   type        text | longtext | multiplechoice | multipleselect | numeric
 *               | date | email | phone | url
 *   question    the question text
 *   required    bool
 *   points      how much it is worth (0 = not graded)
 *   image       optional image URL
 *   explanation shown after submitting, when answer review is on
 *   options     choices, for the two choice types
 *   correct     0-based indices of the right choices
 *   answer      the expected answer for typed questions; alternatives can
 *               be separated with |
 *
 * Scoring is by points: score = points earned ÷ points available × 100.
 */
final class Quiz
{
    public const TYPES = ['text', 'longtext', 'multiplechoice', 'multipleselect', 'numeric', 'date', 'email', 'phone', 'url'];
    public const MAX_QUESTIONS = 100;
    public const MAX_OPTIONS = 10;

    /** @return array<string,mixed> */
    public static function settings(int|WP_Post $post): array
    {
        $post = get_post($post);
        $id = $post instanceof WP_Post ? $post->ID : 0;
        $flag = static function (string $k, bool $default) use ($id): bool {
            $v = get_post_meta($id, '_slms_' . $k, true);
            return $v === '' ? $default : $v === '1';
        };
        $int = static fn(string $k, int $default): int => get_post_meta($id, '_slms_' . $k, true) === '' ? $default : (int)get_post_meta($id, '_slms_' . $k, true);

        return [
            'id'                 => $id,
            'title'              => $post instanceof WP_Post ? get_the_title($post) : '',
            'course_id'          => (int)get_post_meta($id, '_slms_course', true),
            'passing_score'      => max(0, min(100, $int('passing_score', 70))),
            'time_limit'         => max(0, $int('time_limit', 0)),
            'max_attempts'       => max(0, $int('max_attempts', 0)),
            'allow_retake'       => $flag('allow_retake', false),
            'require_login'      => $flag('require_login', true),
            'require_enrollment' => $flag('require_enrollment', true),
            'review_answers'     => $flag('review_answers', true),
            'show_results'       => $flag('show_results', true),
            'collect_email'      => $flag('collect_email', false),
            'weight'             => max(0.0, min(100.0, (float)get_post_meta($id, '_slms_weight', true))),
            'per_page'           => max(1, min(50, $int('per_page', 5))),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function questions(int $quizId): array
    {
        $raw = get_post_meta($quizId, '_slms_questions', true);
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        return self::sanitizeQuestions(is_array($list) ? $list : []);
    }

    /**
     * Clean a question list from the builder (or anywhere else).
     *
     * @param array<int,mixed> $list
     * @return list<array<string,mixed>>
     */
    public static function sanitizeQuestions(array $list): array
    {
        $out = [];
        $seen = [];
        $next = 1;
        foreach ($list as $q) {
            if (is_array($q) && isset($q['id'])) {
                $next = max($next, (int)$q['id'] + 1);
            }
        }
        foreach ($list as $q) {
            if (!is_array($q) || count($out) >= self::MAX_QUESTIONS) {
                continue;
            }
            $text = self::plain((string)($q['question'] ?? ''), true, 2000);
            if ($text === '') {
                continue;
            }
            $id = (int)($q['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                $id = $next++;
            }
            $seen[$id] = true;

            $type = strtolower((string)($q['type'] ?? 'text'));
            if (!in_array($type, self::TYPES, true)) {
                $type = 'text';
            }
            $options = [];
            $correct = [];
            if ($type === 'multiplechoice' || $type === 'multipleselect') {
                // Empty answer rows are dropped. The ticks were made against
                // the rows as the author saw them, so each tick is moved with
                // its answer — otherwise every tick after a blank row would
                // land on the answer below it.
                $ticked = [];
                foreach ((array)($q['correct'] ?? []) as $c) {
                    if (is_numeric($c)) {
                        $ticked[(int)$c] = true;
                    }
                }
                foreach (array_values((array)($q['options'] ?? [])) as $i => $opt) {
                    $opt = self::plain(is_scalar($opt) ? (string)$opt : '', false, 300);
                    if ($opt === '' || count($options) >= self::MAX_OPTIONS) {
                        continue;
                    }
                    if (isset($ticked[$i])) {
                        $correct[] = count($options);
                    }
                    $options[] = $opt;
                }
                $correct = array_values(array_unique($correct));
                sort($correct);
                if ($type === 'multiplechoice') {
                    $correct = array_slice($correct, 0, 1);
                }
            }

            $out[] = [
                'id'          => $id,
                'type'        => $type,
                'question'    => $text,
                'required'    => !empty($q['required']),
                'points'      => max(0.0, min(1000.0, round((float)($q['points'] ?? 1), 2))),
                'image'       => esc_url_raw((string)($q['image'] ?? ''), ['http', 'https']),
                'explanation' => self::plain((string)($q['explanation'] ?? ''), true, 2000),
                'options'     => $options,
                'correct'     => $correct,
                'answer'      => $type === 'multiplechoice' || $type === 'multipleselect' ? '' : self::plain((string)($q['answer'] ?? ''), false, 500),
            ];
        }
        return $out;
    }

    /**
     * Plain text as typed. Everything is escaped where it is shown, so
     * characters such as < > & % are kept as they are rather than being
     * stripped or turned into entities (which would make "x<y" unmatchable).
     */
    private static function plain(string $v, bool $multiline, int $max): string
    {
        $v = wp_check_invalid_utf8(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = $multiline ? (string)preg_replace("/\r\n?/", "\n", $v) : (string)preg_replace('/\s+/u', ' ', $v);
        return trim(mb_substr($v, 0, $max));
    }

    /** Text compared the forgiving way: case, spacing and end punctuation don't matter. */
    private static function normText(string $v): string
    {
        $v = mb_strtolower(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $v = (string)preg_replace('/\s+/u', ' ', trim($v));
        return trim($v, " \t.,;:!?'\"“”‘’");
    }

    /** A number as typed: "1,5", "1 000", "1,000.50", "R 15" → float, or null. */
    private static function number(string $v): ?float
    {
        $v = (string)preg_replace('/[^0-9,.\-]/', '', str_replace(["\u{00A0}", ' '], '', $v));
        if ($v === '' || $v === '-') {
            return null;
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);                         // 1,000.50
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+$/', $v)) {
            $v = str_replace(',', '', $v);                         // 1,000
        } else {
            $v = str_replace(',', '.', $v);                        // 1,5
        }
        return is_numeric($v) ? (float)$v : null;
    }

    private static function date(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $v, $m)) {
            // 24/10/1964 — day first, as written outside the US.
            $v = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        $ts = strtotime($v . ' 00:00:00 UTC');
        return $ts === false ? null : gmdate('Y-m-d', $ts);
    }

    private static function url(string $v): string
    {
        $v = mb_strtolower(trim($v));
        $v = (string)preg_replace('#^https?://#', '', $v);
        $v = (string)preg_replace('#^www\.#', '', $v);
        return rtrim($v, '/');
    }

    /** Does a typed answer match one of the expected answers (separated by |)? */
    public static function matches(string $type, string $given, string $expected): bool
    {
        foreach (explode('|', $expected) as $want) {
            $want = trim($want);
            if ($want === '') {
                continue;
            }
            $ok = match ($type) {
                'numeric' => ($a = self::number($given)) !== null && ($b = self::number($want)) !== null
                    && abs($a - $b) <= max(0.001, abs($b) * 1e-9),
                'date'    => ($a = self::date($given)) !== null && $a === self::date($want),
                'email'   => strtolower(trim($given)) === strtolower(trim($want)),
                'url'     => self::url($given) === self::url($want),
                'phone'   => ($a = Helpers::msisdn($given)) !== '' && $a === Helpers::msisdn($want),
                default   => self::normText($given) === self::normText($want),
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /**
     * Score a submission.
     *
     * @param list<array<string,mixed>> $questions
     * @param array<int|string,mixed>   $answers  question id => answer
     * @return array<string,mixed>
     */
    public static function score(array $questions, array $answers, int $passingScore): array
    {
        $correct = 0;
        $answered = 0;
        $earned = 0.0;
        $available = 0.0;
        $results = [];

        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $points = (float)$q['points'];
            $available += $points;
            $a = $answers[$qid] ?? ($answers[(string)$qid] ?? null);

            if (is_array($a)) {
                $a = array_values(array_filter(array_map(static fn($x) => is_scalar($x) ? trim((string)$x) : '', $a), static fn($x) => $x !== ''));
            } elseif (is_scalar($a)) {
                $a = mb_substr(trim((string)$a), 0, 5000);
            } else {
                $a = null;
            }

            if ($a === null || $a === '' || $a === []) {
                $results[$qid] = ['correct' => false, 'answered' => false, 'points' => $points];
                continue;
            }
            $answered++;

            $ok = false;
            switch ($q['type']) {
                case 'multiplechoice':
                    $pick = is_array($a) ? ($a[0] ?? '') : $a;
                    $ok = is_numeric($pick) && in_array((int)$pick, array_map('intval', $q['correct']), true);
                    break;
                case 'multipleselect':
                    $got = array_values(array_unique(array_map('intval', array_filter(is_array($a) ? $a : [$a], 'is_numeric'))));
                    $want = array_values(array_unique(array_map('intval', $q['correct'])));
                    sort($got);
                    sort($want);
                    $ok = $want !== [] && $got === $want;
                    break;
                default:
                    $ok = !is_array($a) && (string)$q['answer'] !== '' && self::matches((string)$q['type'], $a, (string)$q['answer']);
            }

            if ($ok) {
                $correct++;
                $earned += $points;
            }
            $results[$qid] = ['correct' => $ok, 'answered' => true, 'user_answer' => $a, 'points' => $points];
        }

        $score = $available > 0 ? (int)round($earned / $available * 100) : 0;

        return [
            'total'         => count($questions),
            'answered'      => $answered,
            'correct'       => $correct,
            'points_earned' => round($earned, 2),
            'points_total'  => round($available, 2),
            'score'         => $score,
            'passed'        => $score >= $passingScore,
            'results'       => $results,
        ];
    }

    /** The text of an answer, with choice indices turned into their labels. */
    public static function answerLabel(array $q, mixed $value): string
    {
        $opts = (array)($q['options'] ?? []);
        if ($q['type'] === 'multiplechoice' || $q['type'] === 'multipleselect') {
            $vals = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
            return implode(', ', array_map(static fn($i) => (string)($opts[(int)$i] ?? $i), $vals));
        }
        return is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
    }

    public static function correctLabel(array $q): string
    {
        if ($q['type'] === 'multiplechoice' || $q['type'] === 'multipleselect') {
            return self::answerLabel($q, $q['correct']);
        }
        return str_replace('|', ' / ', (string)$q['answer']);
    }

    /**
     * Review rows for a saved result, for the results modal and the
     * already-taken view.
     *
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    public static function review(int $quizId, array $result, bool $withCorrect): array
    {
        $rows = [];
        $n = 0;
        $map = is_array($result['results'] ?? null) ? $result['results'] : [];
        foreach (self::questions($quizId) as $q) {
            $n++;
            $r = $map[$q['id']] ?? ($map[(string)$q['id']] ?? []);
            $rows[] = [
                'n'           => $n,
                'question'    => $q['question'],
                'points'      => $q['points'],
                'graded'      => $q['points'] > 0,
                'answered'    => !empty($r['answered']),
                'is_correct'  => !empty($r['correct']),
                'user'        => !empty($r['answered']) ? self::answerLabel($q, $r['user_answer'] ?? '') : '',
                'correct'     => $withCorrect ? self::correctLabel($q) : '',
                'explanation' => $q['explanation'],
            ];
        }
        return $rows;
    }

    public static function formatPoints(float $pts): string
    {
        $n = rtrim(rtrim(number_format($pts, 2, '.', ''), '0'), '.');
        /* translators: %s: number of points */
        return sprintf(_n('%s pt', '%s pts', $pts == 1.0 ? 1 : 2, 'sepo-lms'), $n);
    }
}
