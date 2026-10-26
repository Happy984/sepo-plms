<?php

declare(strict_types=1);

namespace SepoLms;

use WP_Post;

/**
 * Assignment settings and rules.
 *
 * Two kinds of assignment:
 *   - upload: the learner hands in files and/or a written answer against the
 *     listed requirements; staff grade it out of the assignment's points.
 *   - quiz:   the learner takes a linked quiz from the assignment page; the
 *     quiz score becomes the assignment grade, less any late penalty.
 *
 * Both can carry a due date, a late policy, a weight in the course grade
 * and proctoring (screen recording and focus lock).
 */
final class Assignments
{
    /** File-type groups offered in the editor, with the extensions each allows. */
    public const TYPE_GROUPS = [
        'pdf'          => ['pdf'],
        'documents'    => ['doc', 'docx', 'odt', 'rtf', 'txt', 'md'],
        'spreadsheets' => ['xls', 'xlsx', 'ods', 'csv'],
        'slides'       => ['ppt', 'pptx', 'odp', 'key'],
        'images'       => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'],
        'archives'     => ['zip', 'rar', '7z'],
        'audio'        => ['mp3', 'm4a', 'wav', 'ogg'],
        'video'        => ['mp4', 'mov', 'webm', 'm4v'],
    ];

    /** @var array<int,array<string,mixed>> */
    private static array $cache = [];

    /** @return array<string,string> group => label */
    public static function typeGroupLabels(): array
    {
        return [
            'pdf'          => __('PDF', 'sepo-lms'),
            'documents'    => __('Documents (Word, text)', 'sepo-lms'),
            'spreadsheets' => __('Spreadsheets', 'sepo-lms'),
            'slides'       => __('Presentations', 'sepo-lms'),
            'images'       => __('Images', 'sepo-lms'),
            'archives'     => __('Archives (zip)', 'sepo-lms'),
            'audio'        => __('Audio', 'sepo-lms'),
            'video'        => __('Video', 'sepo-lms'),
        ];
    }

    /**
     * @return array{id:int,title:string,course_id:int,type:string,quiz_id:int,due:string,due_ts:int,late:string,
     *   late_penalty:float,points:float,weight:float,pass_mark:float,groups:list<string>,extensions:list<string>,
     *   max_files:int,max_bytes:int,text:string,min_words:int,requirements:list<string>,confirm:bool,resubmit:string,
     *   proctor:array{record:bool,lockdown:bool,leaves:int,note:string}}
     */
    public static function settings(int|WP_Post $post): array
    {
        $post = get_post($post);
        $id = $post instanceof WP_Post ? $post->ID : 0;
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $m = static fn(string $k): string => $id > 0 ? (string)get_post_meta($id, '_slms_' . $k, true) : '';

        $groups = array_values(array_intersect(array_filter(explode(',', $m('file_types'))), array_keys(self::TYPE_GROUPS)));
        if ($groups === [] && $m('file_types') === '') {
            $groups = ['pdf', 'documents'];
        }
        $extensions = [];
        foreach ($groups as $g) {
            $extensions = array_merge($extensions, self::TYPE_GROUPS[$g]);
        }
        foreach (preg_split('/[\s,;.]+/', strtolower($m('extra_types'))) ?: [] as $ext) {
            $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
            if ($ext !== '' && !Storage::isBlocked($ext)) {
                $extensions[] = $ext;
            }
        }
        $siteMax = max(1, (int)Settings::get('upload_max_mb')) * MB_IN_BYTES;
        $ownMax = (int)$m('max_mb') * MB_IN_BYTES;
        $maxBytes = min($ownMax > 0 ? $ownMax : $siteMax, $siteMax, (int)wp_max_upload_size());

        $due = $m('due');
        $dueTs = 0;
        if ($due !== '') {
            $dt = date_create_immutable($due, wp_timezone());
            $dueTs = $dt !== false ? $dt->getTimestamp() : 0;
        }
        $type = $m('kind') === 'quiz' ? 'quiz' : 'upload';
        $text = in_array($m('text_answer'), ['off', 'optional', 'required'], true) ? $m('text_answer') : 'optional';
        $resubmit = in_array($m('resubmit'), ['until_graded', 'until_due', 'never'], true) ? $m('resubmit') : 'until_graded';

        return self::$cache[$id] = [
            'id'           => $id,
            'title'        => $id > 0 ? get_the_title($id) : '',
            'course_id'    => (int)$m('course'),
            'type'         => $type,
            'quiz_id'      => $type === 'quiz' ? (int)$m('quiz') : 0,
            'due'          => $due,
            'due_ts'       => $dueTs,
            'late'         => $m('late') === 'deny' ? 'deny' : 'allow',
            'late_penalty' => min(100.0, max(0.0, (float)$m('late_penalty'))),
            'points'       => $m('points') !== '' ? max(1.0, (float)$m('points')) : 100.0,
            'weight'       => max(0.0, (float)$m('weight')),
            'pass_mark'    => $m('pass_mark') !== '' ? min(100.0, max(0.0, (float)$m('pass_mark'))) : 50.0,
            'groups'       => $groups,
            'extensions'   => array_values(array_unique($extensions)),
            'max_files'    => $m('max_files') !== '' ? max(0, min(20, (int)$m('max_files'))) : 3,
            'max_bytes'    => $maxBytes,
            'text'         => $text,
            'min_words'    => max(0, (int)$m('min_words')),
            'requirements' => Course::lines($m('requirements')),
            'confirm'      => $m('confirm_requirements') === '1',
            'resubmit'     => $resubmit,
            'proctor'      => Proctor::settingsFor($id),
        ];
    }

    public static function flush(?int $id = null): void
    {
        if ($id === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$id]);
        }
    }

    /** The quiz a quiz-type assignment uses, or 0. */
    public static function linkedQuiz(int $assignmentId): int
    {
        if ((string)get_post_meta($assignmentId, '_slms_kind', true) !== 'quiz') {
            return 0;
        }
        $quiz = (int)get_post_meta($assignmentId, '_slms_quiz', true);
        return $quiz > 0 && get_post_type($quiz) === PostTypes::QUIZ ? $quiz : 0;
    }

    /** Quiz IDs used by assignments in a course (their scores count through the assignment). @return list<int> */
    public static function quizzesUsedBy(int $courseId): array
    {
        $out = [];
        foreach (Course::items($courseId, true) as $p) {
            if ($p->post_type === PostTypes::ASSIGNMENT) {
                $q = self::linkedQuiz($p->ID);
                if ($q > 0) {
                    $out[] = $q;
                }
            }
        }
        return $out;
    }

    /** The assignment that uses this quiz, or 0. */
    public static function forQuiz(int $quizId): int
    {
        $ids = get_posts([
            'post_type'      => PostTypes::ASSIGNMENT,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                ['key' => '_slms_kind', 'value' => 'quiz'],
                ['key' => '_slms_quiz', 'value' => (string)$quizId],
            ],
        ]);
        return (int)($ids[0] ?? 0);
    }

    public static function isPastDue(array $s, ?int $at = null): bool
    {
        return $s['due_ts'] > 0 && ($at ?? time()) > $s['due_ts'];
    }

    /** Whole days late (any part of a day counts as one). */
    public static function daysLate(array $s, int $at): int
    {
        if ($s['due_ts'] <= 0 || $at <= $s['due_ts']) {
            return 0;
        }
        return (int)ceil(($at - $s['due_ts']) / DAY_IN_SECONDS);
    }

    /** A percentage after the late penalty, never below zero. */
    public static function applyPenalty(float $percent, array $s, int $daysLate): float
    {
        if ($daysLate <= 0 || $s['late_penalty'] <= 0) {
            return round($percent, 2);
        }
        return round(max(0.0, $percent - $s['late_penalty'] * $daysLate), 2);
    }

    /**
     * Can this learner hand in (or hand in again) right now? Returns '' when
     * they can, or the reason they cannot.
     *
     * @param array<string,mixed>      $s
     * @param array<string,mixed>|null $existing
     */
    public static function submitBlock(array $s, ?array $existing): string
    {
        if ($s['late'] === 'deny' && self::isPastDue($s)) {
            return __('The due date has passed and late work is not accepted.', 'sepo-lms');
        }
        if ($existing === null || $existing['status'] === 'returned') {
            return '';
        }
        return match ($s['resubmit']) {
            'never'        => __('You have already handed this in.', 'sepo-lms'),
            'until_due'    => self::isPastDue($s) ? __('You can no longer change your work: the due date has passed.', 'sepo-lms') : '',
            default        => $existing['status'] === 'graded' ? __('This has been graded, so it can no longer be changed.', 'sepo-lms') : '',
        };
    }

    /** "Due Fri 14 Nov, 17:00" in site time. */
    public static function dueLabel(array $s): string
    {
        if ($s['due_ts'] <= 0) {
            return '';
        }
        return wp_date((string)get_option('date_format') . ' ' . (string)get_option('time_format'), $s['due_ts']);
    }

    /** Words in a plain-text answer. */
    public static function wordCount(string $text): int
    {
        $text = trim(wp_strip_all_tags($text));
        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /** The accept attribute for the file input. */
    public static function acceptAttr(array $s): string
    {
        return implode(',', array_map(static fn($e) => '.' . $e, $s['extensions']));
    }
}
