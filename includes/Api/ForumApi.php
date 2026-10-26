<?php

declare(strict_types=1);

namespace SepoLms\Api;

use SepoLms\Access;
use SepoLms\Forums;
use SepoLms\Helpers;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Course forum endpoints.
 *
 *   GET  /forum/topics   course, forum, q, page
 *   GET  /forum/topic    id
 *   POST /forum/post     course, forum, parent, title, body
 *   POST /forum/edit     id, title, body
 *   POST /forum/delete   id
 *   POST /forum/pin      id, pinned
 */
final class ForumApi extends Base
{
    private const PER_PAGE = 15;
    private const EDIT_WINDOW = 30 * MINUTE_IN_SECONDS;

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        self::route('/forum/topics', WP_REST_Server::READABLE, [self::class, 'topics']);
        self::route('/forum/topic', WP_REST_Server::READABLE, [self::class, 'topic']);
        self::route('/forum/post', WP_REST_Server::CREATABLE, [self::class, 'post']);
        self::route('/forum/edit', WP_REST_Server::CREATABLE, [self::class, 'edit']);
        self::route('/forum/delete', WP_REST_Server::CREATABLE, [self::class, 'delete']);
        self::route('/forum/pin', WP_REST_Server::CREATABLE, [self::class, 'pin']);
    }

    /** @return array{0:int,1:array<string,mixed>} course ID and forum */
    private static function forumFrom(WP_REST_Request $req): array
    {
        $courseId = absint($req->get_param('course'));
        $forum = Forums::forum($courseId, sanitize_key((string)$req->get_param('forum')));
        if ($forum === null || !Access::inClassroom($courseId, get_current_user_id())) {
            throw new \DomainException(__('Forum not found.', 'sepo-lms'), 404);
        }
        return [$courseId, $forum];
    }

    public static function topics(WP_REST_Request $req): WP_REST_Response
    {
        [$courseId, $forum] = self::forumFrom($req);
        $uid = get_current_user_id();
        $page = max(1, absint($req->get_param('page')));
        $res = Forums::topics($courseId, $forum['key'], sanitize_text_field((string)$req->get_param('q')), self::PER_PAGE, $page);
        $staff = Access::canTeach($courseId, $uid);
        return self::ok([
            'forum'     => $forum,
            'can_start' => Forums::canStartTopic($courseId, $forum, $uid),
            'staff'     => $staff,
            'total'     => $res['total'],
            'pages'     => (int)max(1, ceil($res['total'] / self::PER_PAGE)),
            'page'      => $page,
            'topics'    => array_map(static fn($t) => self::presentTopic($t, $courseId, $uid, $forum), $res['rows']),
        ]);
    }

    public static function topic(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $uid = get_current_user_id();
        $topic = Forums::find(absint($req->get_param('id')));
        if ($topic === null || $topic['parent_id'] !== 0 || !Access::inClassroom($topic['course_id'], $uid)) {
            return self::error(__('Topic not found.', 'sepo-lms'), 404);
        }
        $forum = Forums::forum($topic['course_id'], $topic['forum']);
        if ($forum === null) {
            return self::error(__('Topic not found.', 'sepo-lms'), 404);
        }
        $staff = Access::canTeach($topic['course_id'], $uid);
        $replies = Forums::replies($topic['id']);
        $hidden = 0;
        if ($forum['type'] === 'post_first' && !$staff && !Forums::hasPosted($topic['id'], $uid)) {
            $visible = [];
            foreach ($replies as $r) {
                if ($r['user_id'] === $uid || Access::canTeach($topic['course_id'], $r['user_id'])) {
                    $visible[] = $r;
                } else {
                    $hidden++;
                }
            }
            $replies = $visible;
        }
        return self::ok([
            'forum'     => $forum,
            'topic'     => self::presentPost($topic, $topic['course_id'], $uid),
            'replies'   => array_map(static fn($r) => self::presentPost($r, $topic['course_id'], $uid), $replies),
            'hidden'    => $hidden,
            'can_reply' => Forums::canReply($topic['course_id'], $forum, $uid),
            'staff'     => $staff,
        ]);
    }

    public static function post(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        [$courseId, $forum] = self::forumFrom($req);
        $uid = get_current_user_id();
        $parent = absint($req->get_param('parent'));
        $body = trim(self::text($req->get_param('body'), 20000));
        $title = sanitize_text_field((string)$req->get_param('title'));
        if ($body === '') {
            return self::error(__('Write something first.', 'sepo-lms'), 422);
        }
        if ($parent > 0) {
            $topic = Forums::find($parent);
            if ($topic === null || $topic['parent_id'] !== 0 || $topic['course_id'] !== $courseId || $topic['forum'] !== $forum['key']) {
                return self::error(__('Topic not found.', 'sepo-lms'), 404);
            }
            if (!Forums::canReply($courseId, $forum, $uid)) {
                return self::error(__('Only course staff can post here.', 'sepo-lms'), 403);
            }
        } else {
            if (!Forums::canStartTopic($courseId, $forum, $uid)) {
                return self::error(__('Only course staff can post here.', 'sepo-lms'), 403);
            }
            if ($title === '') {
                return self::error(__('Give your topic a title.', 'sepo-lms'), 422);
            }
        }
        $flood = 'slms_forum_rate_' . $uid;
        $count = (int)get_transient($flood);
        if ($count >= 10) {
            return self::error(__('You are posting too quickly. Wait a minute and try again.', 'sepo-lms'), 429);
        }
        set_transient($flood, $count + 1, MINUTE_IN_SECONDS);

        $saved = Forums::create($courseId, $forum['key'], $parent, $uid, $title, $body);
        if ($parent === 0 && $forum['type'] === 'announcements' && $forum['email']) {
            Forums::queueAnnouncement((int)$saved['id']);
        }
        return self::ok(['post' => self::presentPost($saved, $courseId, $uid)]);
    }

    public static function edit(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $uid = get_current_user_id();
        $row = Forums::find(absint($req->get_param('id')));
        if ($row === null || !self::canEdit($row, $uid)) {
            return self::error(__('You cannot edit this post.', 'sepo-lms'), 403);
        }
        $body = trim(self::text($req->get_param('body'), 20000));
        if ($body === '') {
            return self::error(__('Write something first.', 'sepo-lms'), 422);
        }
        Forums::update($row['id'], sanitize_text_field((string)$req->get_param('title')), $body);
        return self::ok(['post' => self::presentPost(Forums::find($row['id']) ?? $row, $row['course_id'], $uid)]);
    }

    public static function delete(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $uid = get_current_user_id();
        $row = Forums::find(absint($req->get_param('id')));
        if ($row === null || ($row['user_id'] !== $uid && !Access::canTeach($row['course_id'], $uid))) {
            return self::error(__('You cannot delete this post.', 'sepo-lms'), 403);
        }
        Forums::delete($row['id']);
        return self::ok(['deleted' => true]);
    }

    public static function pin(WP_REST_Request $req): WP_REST_Response|\WP_Error
    {
        $row = Forums::find(absint($req->get_param('id')));
        if ($row === null || $row['parent_id'] !== 0 || !Access::canTeach($row['course_id'])) {
            return self::error(__('Topic not found.', 'sepo-lms'), 404);
        }
        Forums::setPinned($row['id'], rest_sanitize_boolean($req->get_param('pinned')));
        return self::ok(['pinned' => rest_sanitize_boolean($req->get_param('pinned'))]);
    }

    private static function canEdit(array $row, int $uid): bool
    {
        if (Access::canTeach($row['course_id'], $uid)) {
            return true;
        }
        return $row['user_id'] === $uid && time() - (int)strtotime($row['created'] . ' UTC') < self::EDIT_WINDOW;
    }

    /** @return array<string,mixed> */
    private static function author(int $userId, int $courseId): array
    {
        $u = $userId > 0 ? get_userdata($userId) : false;
        return [
            'name'   => $u ? (string)$u->display_name : __('Former member', 'sepo-lms'),
            'avatar' => $u ? (string)get_avatar_url($userId, ['size' => 64]) : '',
            'staff'  => $userId > 0 && Access::canTeach($courseId, $userId),
        ];
    }

    /** @return array<string,mixed> */
    private static function presentTopic(array $t, int $courseId, int $uid, array $forum): array
    {
        $out = self::presentPost($t, $courseId, $uid);
        unset($out['html']);
        $out['excerpt'] = wp_trim_words($t['body'], 24, '…');
        $out['replies'] = $t['replies'];
        $out['last'] = Helpers::datetime((string)$t['last_activity']);
        $out['answered'] = $forum['type'] === 'post_first' ? Forums::hasPosted($t['id'], $uid) : null;
        return $out;
    }

    /** @return array<string,mixed> */
    private static function presentPost(array $p, int $courseId, int $uid): array
    {
        return [
            'id'         => (int)$p['id'],
            'title'      => (string)$p['title'],
            'html'       => Forums::format((string)$p['body']),
            'body'       => (string)$p['body'],
            'pinned'     => !empty($p['pinned']),
            'author'     => self::author((int)$p['user_id'], $courseId),
            'mine'       => (int)$p['user_id'] === $uid,
            'created'    => Helpers::datetime((string)$p['created']),
            'edited'     => $p['updated'] !== $p['created'],
            'can_edit'   => self::canEdit($p, $uid),
            'can_delete' => (int)$p['user_id'] === $uid || Access::canTeach($courseId, $uid),
        ];
    }
}
