<?php

declare(strict_types=1);

namespace SepoLms\Api;

use SepoLms\Access;
use SepoLms\Attendance;
use SepoLms\Certificates;
use SepoLms\Chat;
use SepoLms\Helpers;
use SepoLms\PostTypes;
use SepoLms\Settings;
use SepoLms\Study;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Study tools, attendance, chat and certificate endpoints for the course
 * and lesson pages.
 *
 * Study tools (the learner's own data):
 *   GET  /study/todos      course
 *   POST /study/todo       course, item, text, due
 *   POST /study/todo/update id, text, done, due
 *   POST /study/todo/delete id
 *   POST /study/todo/order  ids[]
 *   POST /study/todo/clear  course
 *   GET  /study/notes       course (-1 = all), q
 *   GET  /study/note        id
 *   POST /study/note        course, item, title          (create)
 *   POST /study/note/save   id, title, icon, blocks
 *   POST /study/note/delete id
 *
 * Attendance:
 *   GET  /attendance        course
 *   POST /attendance/checkin session, code
 *
 * Chat:
 *   GET  /chat/rooms        course
 *   GET  /chat/messages     room, after, before
 *   POST /chat/send         room, body
 *   POST /chat/delete       id
 *
 * Certificates:
 *   POST /certificate/claim course
 */
final class ClassroomApi extends Base
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        self::route('/study/todos', WP_REST_Server::READABLE, [self::class, 'todos']);
        self::route('/study/todo', WP_REST_Server::CREATABLE, [self::class, 'todoAdd']);
        self::route('/study/todo/update', WP_REST_Server::CREATABLE, [self::class, 'todoUpdate']);
        self::route('/study/todo/delete', WP_REST_Server::CREATABLE, [self::class, 'todoDelete']);
        self::route('/study/todo/order', WP_REST_Server::CREATABLE, [self::class, 'todoOrder']);
        self::route('/study/todo/clear', WP_REST_Server::CREATABLE, [self::class, 'todoClear']);
        self::route('/study/notes', WP_REST_Server::READABLE, [self::class, 'notes']);
        self::route('/study/note', WP_REST_Server::READABLE, [self::class, 'note']);
        self::route('/study/note', WP_REST_Server::CREATABLE, [self::class, 'noteCreate']);
        self::route('/study/note/save', WP_REST_Server::CREATABLE, [self::class, 'noteSave']);
        self::route('/study/note/delete', WP_REST_Server::CREATABLE, [self::class, 'noteDelete']);

        self::route('/attendance', WP_REST_Server::READABLE, [self::class, 'attendance']);
        self::route('/attendance/checkin', WP_REST_Server::CREATABLE, [self::class, 'checkin']);

        self::route('/chat/rooms', WP_REST_Server::READABLE, [self::class, 'chatRooms']);
        self::route('/chat/messages', WP_REST_Server::READABLE, [self::class, 'chatMessages']);
        self::route('/chat/send', WP_REST_Server::CREATABLE, [self::class, 'chatSend']);
        self::route('/chat/delete', WP_REST_Server::CREATABLE, [self::class, 'chatDelete']);

        self::route('/certificate/claim', WP_REST_Server::CREATABLE, [self::class, 'certificateClaim']);
    }

    // ---------------------------------------------------------------- study

    private static function studyOn(): void
    {
        if (!Settings::feature('study')) {
            throw new \DomainException(__('Study tools are switched off.', 'sepo-lms'), 403);
        }
    }

    /** A course the learner may attach study items to (0 = none). */
    private static function studyCourse(WP_REST_Request $req): int
    {
        $courseId = absint($req->get_param('course'));
        if ($courseId > 0 && !Access::inClassroom($courseId, get_current_user_id())) {
            throw new \DomainException(__('Course not found.', 'sepo-lms'), 404);
        }
        return $courseId;
    }

    private static function studyItem(WP_REST_Request $req, int $courseId): int
    {
        $itemId = absint($req->get_param('item'));
        return $itemId > 0 && $courseId > 0 && PostTypes::courseOf($itemId) === $courseId ? $itemId : 0;
    }

    public static function todos(WP_REST_Request $req): WP_REST_Response
    {
        self::studyOn();
        return self::ok(['items' => Study::todos(get_current_user_id(), self::studyCourse($req))]);
    }

    public static function todoAdd(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        self::studyOn();
        $courseId = self::studyCourse($req);
        $text = sanitize_text_field((string)$req->get_param('text'));
        if ($text === '') {
            return self::error(__('Type the task first.', 'sepo-lms'), 422);
        }
        $item = Study::addTodo(get_current_user_id(), $courseId, self::studyItem($req, $courseId), $text, (string)$req->get_param('due'));
        return self::ok(['item' => $item]);
    }

    public static function todoUpdate(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        self::studyOn();
        $patch = [];
        foreach (['text', 'done', 'due'] as $k) {
            if ($req->has_param($k)) {
                $patch[$k] = $req->get_param($k);
            }
        }
        $item = Study::updateTodo(absint($req->get_param('id')), get_current_user_id(), $patch);
        return $item === null ? self::error(__('Task not found.', 'sepo-lms'), 404) : self::ok(['item' => $item]);
    }

    public static function todoDelete(WP_REST_Request $req): WP_REST_Response
    {
        self::studyOn();
        return self::ok(['deleted' => Study::deleteTodo(absint($req->get_param('id')), get_current_user_id())]);
    }

    public static function todoOrder(WP_REST_Request $req): WP_REST_Response
    {
        self::studyOn();
        Study::reorderTodos(get_current_user_id(), array_map('absint', (array)$req->get_param('ids')));
        return self::ok(['ok' => true]);
    }

    public static function todoClear(WP_REST_Request $req): WP_REST_Response
    {
        self::studyOn();
        return self::ok(['removed' => Study::clearDone(get_current_user_id(), self::studyCourse($req))]);
    }

    public static function notes(WP_REST_Request $req): WP_REST_Response
    {
        self::studyOn();
        $course = $req->get_param('course');
        $courseId = $course === null || (int)$course < 0 ? -1 : self::studyCourse($req);
        $rows = Study::notes(get_current_user_id(), $courseId, sanitize_text_field((string)$req->get_param('q')));
        $titles = [];
        foreach ($rows as &$r) {
            $titles[$r['course_id']] ??= $r['course_id'] > 0 ? get_the_title($r['course_id']) : '';
            $r['course'] = $titles[$r['course_id']];
            $r['when'] = Helpers::datetime($r['updated']);
            $r['lesson'] = $r['item_id'] > 0 ? get_the_title($r['item_id']) : '';
        }
        unset($r);
        return self::ok(['notes' => $rows]);
    }

    public static function note(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        self::studyOn();
        $note = Study::note(absint($req->get_param('id')), get_current_user_id());
        if ($note === null) {
            return self::error(__('Note not found.', 'sepo-lms'), 404);
        }
        $note['course'] = $note['course_id'] > 0 ? get_the_title($note['course_id']) : '';
        $note['lesson'] = $note['item_id'] > 0 ? get_the_title($note['item_id']) : '';
        $note['lesson_url'] = $note['item_id'] > 0 ? (string)get_permalink($note['item_id']) : '';
        return self::ok(['note' => $note]);
    }

    public static function noteCreate(WP_REST_Request $req): WP_REST_Response
    {
        self::studyOn();
        $courseId = self::studyCourse($req);
        $note = Study::createNote(get_current_user_id(), $courseId, self::studyItem($req, $courseId), sanitize_text_field((string)$req->get_param('title')));
        return self::ok(['note' => $note]);
    }

    public static function noteSave(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        self::studyOn();
        $note = Study::saveNote(
            absint($req->get_param('id')),
            get_current_user_id(),
            $req->has_param('title') ? (string)$req->get_param('title') : null,
            $req->has_param('icon') ? (string)$req->get_param('icon') : null,
            $req->has_param('blocks') ? $req->get_param('blocks') : null
        );
        return $note === null ? self::error(__('Note not found.', 'sepo-lms'), 404) : self::ok(['saved' => true, 'updated' => Helpers::datetime($note['updated'])]);
    }

    public static function noteDelete(WP_REST_Request $req): WP_REST_Response
    {
        self::studyOn();
        return self::ok(['deleted' => Study::deleteNote(absint($req->get_param('id')), get_current_user_id())]);
    }

    // ----------------------------------------------------------- attendance

    public static function attendance(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        if (!Settings::feature('attendance')) {
            return self::error(__('Attendance is switched off.', 'sepo-lms'), 403);
        }
        $courseId = absint($req->get_param('course'));
        $uid = get_current_user_id();
        if (!Access::inClassroom($courseId, $uid)) {
            return self::error(__('Course not found.', 'sepo-lms'), 404);
        }
        $data = Attendance::forLearner($courseId, $uid);
        $fmt = (string)get_option('date_format') . ' ' . (string)get_option('time_format');
        foreach ($data['sessions'] as &$s) {
            $s['when'] = wp_date($fmt, $s['starts_ts']) . ' – ' . wp_date((string)get_option('time_format'), $s['ends_ts']);
            $s['needs_code'] = Attendance::session($s['id'])['code'] !== '';
            unset($s['starts'], $s['ends']);
        }
        unset($s);
        return self::ok($data + ['labels' => Attendance::statusLabels(), 'staff' => Access::canTeach($courseId, $uid)]);
    }

    public static function checkin(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        if (!Settings::feature('attendance')) {
            return self::error(__('Attendance is switched off.', 'sepo-lms'), 403);
        }
        $record = Attendance::checkIn(absint($req->get_param('session')), get_current_user_id(), sanitize_text_field((string)$req->get_param('code')));
        return self::ok(['status' => $record['status'] ?? 'present', 'label' => Attendance::statusLabels()[$record['status'] ?? 'present']]);
    }

    // ----------------------------------------------------------------- chat

    public static function chatRooms(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $courseId = absint($req->get_param('course'));
        $uid = get_current_user_id();
        if (!Chat::enabled() || !Access::inClassroom($courseId, $uid)) {
            return self::error(__('Chat is not available here.', 'sepo-lms'), 404);
        }
        $contacts = [];
        foreach (Chat::contacts($courseId, $uid) as $c) {
            $contacts[] = $c + ['room' => Chat::directRoom($courseId, $uid, $c['id'])];
        }
        return self::ok(['rooms' => Chat::rooms($courseId, $uid), 'contacts' => $contacts, 'me' => $uid]);
    }

    private static function room(WP_REST_Request $req): array
    {
        $room = (string)$req->get_param('room');
        $p = Chat::parse($room);
        if ($p === null || !Chat::canAccess($room, get_current_user_id())) {
            throw new \DomainException(__('Conversation not found.', 'sepo-lms'), 404);
        }
        return [$room, $p['course']];
    }

    public static function chatMessages(WP_REST_Request $req): WP_REST_Response
    {
        [$room, $courseId] = self::room($req);
        $uid = get_current_user_id();
        $msgs = Chat::messages($room, $courseId, $uid, absint($req->get_param('after')), absint($req->get_param('before')));
        if ($msgs !== []) {
            Chat::markRead($uid, $room, (int)end($msgs)['id']);
        }
        return self::ok(['messages' => $msgs]);
    }

    public static function chatSend(WP_REST_Request $req): WP_REST_Response
    {
        [$room, $courseId] = self::room($req);
        $msg = Chat::send($room, $courseId, get_current_user_id(), (string)$req->get_param('body'));
        return self::ok(['message' => $msg]);
    }

    public static function chatDelete(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $row = Chat::find(absint($req->get_param('id')));
        $uid = get_current_user_id();
        if ($row === null || !Chat::canAccess((string)$row['room'], $uid) || ((int)$row['user_id'] !== $uid && !Access::canTeach((int)$row['course_id'], $uid))) {
            return self::error(__('Message not found.', 'sepo-lms'), 404);
        }
        Chat::remove((int)$row['id']);
        return self::ok(['deleted' => true]);
    }

    // --------------------------------------------------------- certificates

    public static function certificateClaim(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $courseId = absint($req->get_param('course'));
        $uid = get_current_user_id();
        $status = Certificates::status($courseId, $uid);
        if (!$status['enabled']) {
            return self::error(__('This course does not issue certificates.', 'sepo-lms'), 404);
        }
        $cert = Certificates::issue($courseId, $uid);
        if ($cert === null) {
            return self::error(implode(' ', $status['reasons']), 409);
        }
        return self::ok(['code' => $cert['code'], 'url' => Certificates::pdfUrl($cert['code']), 'verify' => Certificates::verifyUrl($cert['code'])]);
    }
}
