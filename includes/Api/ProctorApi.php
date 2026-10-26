<?php

declare(strict_types=1);

namespace SepoLms\Api;

use SepoLms\Access;
use SepoLms\Assignments;
use SepoLms\PostTypes;
use SepoLms\Proctor;
use SepoLms\Quiz;
use SepoLms\Settings;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Proctoring session endpoints, called by the browser while a learner works.
 *
 *   POST /proctor/start   item, surface, recording
 *   POST /proctor/chunk   token, part, seq  (raw video body)
 *   POST /proctor/event   token, type, detail
 *   POST /proctor/end     token, reason
 */
final class ProctorApi extends Base
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        self::route('/proctor/start', WP_REST_Server::CREATABLE, [self::class, 'start']);
        self::route('/proctor/chunk', WP_REST_Server::CREATABLE, [self::class, 'chunk']);
        self::route('/proctor/event', WP_REST_Server::CREATABLE, [self::class, 'event']);
        self::route('/proctor/end', WP_REST_Server::CREATABLE, [self::class, 'end']);
    }

    public static function start(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        if (!Settings::feature('proctoring')) {
            return self::error(__('Proctoring is switched off.', 'sepo-lms'), 403);
        }
        $item = get_post(absint($req->get_param('item')));
        if (!$item instanceof WP_Post || !in_array($item->post_type, [PostTypes::ASSIGNMENT, PostTypes::QUIZ], true) || $item->post_status !== 'publish') {
            return self::error(__('Not found.', 'sepo-lms'), 404);
        }
        $rules = Proctor::settingsFor($item->ID);
        if (!Proctor::required($rules)) {
            return self::error(__('This is not proctored.', 'sepo-lms'), 422);
        }
        $uid = get_current_user_id();
        $access = Access::item($item, $uid);
        if (!$access['allowed']) {
            return self::error($access['reason'], 403);
        }
        $recording = rest_sanitize_boolean($req->get_param('recording'));
        if ($rules['record'] && !$recording) {
            return self::error(__('Screen recording is required.', 'sepo-lms'), 422);
        }
        $surface = sanitize_key((string)$req->get_param('surface'));
        if ($rules['record'] && $surface !== '' && $surface !== 'monitor') {
            return self::error(__('Share your entire screen, not a window or a tab.', 'sepo-lms'), 422);
        }
        $courseId = $item->post_type === PostTypes::ASSIGNMENT ? Assignments::settings($item)['course_id'] : Quiz::settings($item)['course_id'];
        $session = Proctor::start($uid, $item->ID, $courseId, $item->post_type === PostTypes::QUIZ ? 'quiz' : 'assignment', $recording, $surface);
        return self::ok([
            'token'  => $session['token'],
            'leaves' => $rules['leaves'],
            'record' => $rules['record'],
        ]);
    }

    public static function chunk(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $session = self::own((string)$req->get_param('token'));
        $mime = sanitize_text_field((string)$req->get_header('content_type'));
        $res = Proctor::appendChunk($session, absint($req->get_param('part')), absint($req->get_param('seq')), (string)$req->get_body(), $mime);
        return self::ok($res);
    }

    public static function event(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $session = self::own((string)$req->get_param('token'));
        if ($session['status'] !== 'active') {
            return self::ok(['violations' => $session['violations'], 'limit' => 0, 'action' => 'none']);
        }
        $res = Proctor::event($session, (string)$req->get_param('type'), (string)$req->get_param('detail'));
        return self::ok($res + ['action' => $res['exceeded'] ? 'submit' : 'none']);
    }

    public static function end(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $session = self::own((string)$req->get_param('token'));
        Proctor::end($session, sanitize_key((string)$req->get_param('reason')) ?: 'closed');
        return self::ok(['ended' => true]);
    }

    /** @return array<string,mixed> */
    private static function own(string $token): array
    {
        $session = Proctor::findByToken($token);
        if ($session === null || $session['user_id'] !== get_current_user_id()) {
            throw new \DomainException(__('Proctoring session not found.', 'sepo-lms'), 404);
        }
        return $session;
    }
}
