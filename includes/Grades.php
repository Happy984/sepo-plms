<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * A learner's course grade from quiz results and graded assignments.
 *
 * Each graded item contributes its percentage. When every graded item has a
 * weight, the grade is the weighted average; otherwise a plain average.
 * A quiz used by a quiz-type assignment counts once, through the assignment.
 */
final class Grades
{
    /**
     * @return array{grade:?float,letter:string,weighted:bool,count:int,passed:int,pending:int,items:list<array<string,mixed>>}
     */
    public static function course(int $courseId, int $userId): array
    {
        $items = [];
        $pending = 0;
        $subs = array_filter(Submissions::forUser($userId), static fn($sub) => (int)$sub['course_id'] === $courseId);

        // A quiz used by an assignment counts once, through the assignment's
        // submission. A result recorded before the quiz was linked still
        // counts on its own until the learner has such a submission.
        $covered = [];
        foreach ($subs as $sub) {
            $s = Assignments::settings((int)$sub['assignment_id']);
            if ($s['type'] === 'quiz' && $s['quiz_id'] > 0) {
                $covered[] = $s['quiz_id'];
            }
        }

        foreach (QuizResults::forUser($userId) as $r) {
            if ((int)$r['course_id'] !== $courseId || in_array((int)$r['quiz_id'], $covered, true)) {
                continue;
            }
            $items[] = ['kind' => 'quiz', 'id' => (int)$r['quiz_id'], 'score' => (float)$r['score'], 'weight' => (float)$r['weight'], 'passed' => !empty($r['passed'])];
        }
        foreach ($subs as $sub) {
            if ($sub['status'] === 'submitted') {
                $pending++;
                continue;
            }
            if ($sub['status'] !== 'graded' || $sub['score'] === null) {
                continue;
            }
            $s = Assignments::settings((int)$sub['assignment_id']);
            $items[] = ['kind' => 'assignment', 'id' => (int)$sub['assignment_id'], 'score' => (float)$sub['score'], 'weight' => $s['weight'], 'passed' => (float)$sub['score'] >= $s['pass_mark']];
        }
        return self::combine($items) + ['pending' => $pending, 'items' => $items];
    }

    /**
     * @param list<array{score:float,weight:float,passed:bool}> $items
     * @return array{grade:?float,letter:string,weighted:bool,count:int,passed:int}
     */
    public static function combine(array $items): array
    {
        $grade = null;
        $weighted = false;
        if ($items !== []) {
            $weights = array_map(static fn($i) => (float)$i['weight'], $items);
            if (!in_array(0.0, $weights, true) && array_sum($weights) > 0) {
                $sum = 0.0;
                foreach ($items as $i) {
                    $sum += (float)$i['score'] * (float)$i['weight'];
                }
                $grade = round($sum / array_sum($weights), 1);
                $weighted = true;
            } else {
                $grade = round(array_sum(array_map(static fn($i) => (float)$i['score'], $items)) / count($items), 1);
            }
        }
        return [
            'grade'    => $grade,
            'letter'   => $grade !== null ? Helpers::letterGrade($grade) : '',
            'weighted' => $weighted,
            'count'    => count($items),
            'passed'   => count(array_filter($items, static fn($i) => !empty($i['passed']))),
        ];
    }
}
