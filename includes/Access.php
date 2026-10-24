<?php

declare(strict_types=1);

namespace SepoLms;

use WP_Post;

/**
 * Who may open what.
 *
 * A lesson or quiz opens when:
 *   - the viewer can edit the course, or
 *   - the viewer is enrolled with access (free course, or payment confirmed),
 *     AND the item's "available from" date has passed,
 *     AND, in a sequential course, the item before it is done.
 *
 * An item counts as done when the learner ticked it off, or — for a quiz —
 * when they have submitted it at least once.
 */
final class Access
{
    public static function canEditCourse(int $courseId): bool
    {
        return current_user_can(Activator::CAP) || ($courseId > 0 && current_user_can('edit_post', $courseId));
    }

    /**
     * The course outline with each item's state for one viewer.
     *
     * @return list<array<string,mixed>>
     */
    public static function outline(int $courseId, int $userId): array
    {
        $course = Course::get($courseId);
        if ($course === null) {
            return [];
        }
        $canEdit = self::canEditCourse($courseId);
        $unlocked = $canEdit || ($userId > 0 && Enrollments::hasAccess($courseId, $userId));
        $doneSet = $userId > 0 ? Progress::doneSet($courseId, $userId) : [];
        $results = $userId > 0 ? QuizResults::forUser($userId) : [];
        $today = current_time('Y-m-d');

        $out = [];
        $prevDone = true;
        foreach (Course::items($courseId) as $p) {
            $isQuiz = $p->post_type === PostTypes::QUIZ;
            $result = $isQuiz ? ($results[$p->ID] ?? null) : null;
            $done = isset($doneSet[$p->ID]) || $result !== null;
            $from = (string)get_post_meta($p->ID, '_slms_available_from', true);
            $dateOk = $from === '' || $from <= $today;
            $prereqOk = !$course['sequential'] || $prevDone;

            if ($canEdit) {
                $accessible = true;
                $reason = '';
            } elseif (!$unlocked) {
                $accessible = false;
                $reason = __('Locked', 'sepo-lms');
            } elseif (!$dateOk) {
                $accessible = false;
                /* translators: %s: date */
                $reason = sprintf(__('Opens %s', 'sepo-lms'), Helpers::date($from));
            } elseif (!$prereqOk) {
                $accessible = false;
                $reason = __('Complete the previous item first', 'sepo-lms');
            } else {
                $accessible = true;
                $reason = '';
            }

            $out[] = [
                'id'         => $p->ID,
                'type'       => $isQuiz ? 'quiz' : 'lesson',
                'title'      => get_the_title($p),
                'url'        => (string)get_permalink($p),
                'note'       => has_excerpt($p) ? (string)get_the_excerpt($p) : '',
                'from'       => $from,
                'done'       => $done,
                'result'     => $result,
                'accessible' => $accessible,
                'reason'     => $reason,
            ];
            $prevDone = $done;
        }
        return $out;
    }

    /**
     * Can this viewer open this lesson or quiz, and if not, why.
     *
     * @return array{allowed:bool,reason:string,course:?array<string,mixed>,code:string}
     */
    public static function item(WP_Post $item, int $userId): array
    {
        $courseId = PostTypes::courseOf($item->ID);
        $course = $courseId > 0 ? Course::get($courseId) : null;

        if ($course === null) {
            // A quiz or lesson with no course is open to anyone (subject to
            // the quiz's own sign-in setting).
            return ['allowed' => true, 'reason' => '', 'course' => null, 'code' => 'ok'];
        }
        if (self::canEditCourse($courseId)) {
            return ['allowed' => true, 'reason' => '', 'course' => $course, 'code' => 'ok'];
        }
        if ($userId <= 0) {
            return ['allowed' => false, 'reason' => __('Please sign in and enrol to open this.', 'sepo-lms'), 'course' => $course, 'code' => 'login'];
        }
        $status = Enrollments::status($courseId, $userId);
        if (!Enrollments::hasAccess($courseId, $userId)) {
            if ($status === null) {
                $msg = $course['free']
                    ? __('Enrol in this course to unlock its lessons.', 'sepo-lms')
                    : __('Enrol and complete payment to unlock this lesson.', 'sepo-lms');
                return ['allowed' => false, 'reason' => $msg, 'course' => $course, 'code' => 'enrol'];
            }
            return ['allowed' => false, 'reason' => __('Your payment is pending. This unlocks once it is confirmed.', 'sepo-lms'), 'course' => $course, 'code' => 'pending'];
        }
        foreach (self::outline($courseId, $userId) as $row) {
            if ($row['id'] === $item->ID) {
                if (!$row['accessible']) {
                    $from = (string)$row['from'];
                    $msg = $from !== '' && $from > current_time('Y-m-d')
                        /* translators: %s: date */
                        ? sprintf(__('This opens on %s.', 'sepo-lms'), Helpers::date($from))
                        : __('Complete the previous item to unlock this one.', 'sepo-lms');
                    return ['allowed' => false, 'reason' => $msg, 'course' => $course, 'code' => 'locked'];
                }
                break;
            }
        }
        return ['allowed' => true, 'reason' => '', 'course' => $course, 'code' => 'ok'];
    }
}
