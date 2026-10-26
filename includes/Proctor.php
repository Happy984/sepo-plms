<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Proctoring sessions for assignments and quizzes.
 *
 * A session starts when the learner agrees to the rules. It can record the
 * learner's whole screen (uploaded in short chunks while they work) and
 * watch for the learner leaving the page: switching tab or window, leaving
 * full screen, or stopping the screen share. Each leave is a violation; when
 * the item sets a limit, going over it submits the work automatically.
 *
 * Browsers do not let a web page block other tabs or apps, so "lockdown"
 * means: the work only shows in full screen, every leave is logged with its
 * time, and the limit is enforced. The recording shows what happened.
 *
 * Recording files: recordings/{yyyy}/{mm}/{token}-{part}.webm. A new part
 * starts whenever screen sharing restarts.
 */
final class Proctor
{
    /** Event types that count as leaving the assessment. */
    public const VIOLATIONS = ['leave', 'fullscreen_exit', 'share_stopped'];

    private const MAX_EVENTS = 1500;
    private const MAX_CHUNK = 16 * MB_IN_BYTES;
    private const MAX_SESSION = 3 * GB_IN_BYTES;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_proctor';
    }

    /**
     * Proctoring rules for a quiz or assignment.
     *
     * @return array{record:bool,lockdown:bool,leaves:int,note:string}
     */
    public static function settingsFor(int $itemId): array
    {
        $off = ['record' => false, 'lockdown' => false, 'leaves' => 0, 'note' => ''];
        if ($itemId <= 0 || !Settings::feature('proctoring')) {
            return $off;
        }
        $m = static fn(string $k): string => (string)get_post_meta($itemId, '_slms_proctor_' . $k, true);
        return [
            'record'   => $m('record') === '1',
            'lockdown' => $m('lockdown') === '1',
            'leaves'   => max(0, min(50, (int)$m('leaves'))),
            'note'     => $m('note'),
        ];
    }

    public static function required(array $s): bool
    {
        return $s['record'] || $s['lockdown'];
    }

    /** @return array<string,mixed> */
    public static function start(int $userId, int $itemId, int $courseId, string $itemType, bool $recording, string $surface): array
    {
        global $wpdb;
        $now = current_time('mysql', true);

        // Any earlier session on this item that never ended is closed as abandoned.
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'UPDATE ' . self::table() . " SET status = 'abandoned', ended = %s WHERE user_id = %d AND item_id = %d AND status = 'active'",
            $now,
            $userId,
            $itemId
        ));

        $token = bin2hex(random_bytes(24));
        $file = $recording ? 'recordings/' . gmdate('Y/m') . '/' . $token : '';
        $wpdb->insert(self::table(), [ // phpcs:ignore WordPress.DB
            'token'     => $token,
            'user_id'   => $userId,
            'item_id'   => $itemId,
            'course_id' => $courseId,
            'item_type' => $itemType,
            'status'    => 'active',
            'recording' => $recording ? 1 : 0,
            'surface'   => substr(sanitize_key($surface), 0, 20),
            'file'      => $file,
            'mime'      => '',
            'events'    => (string)wp_json_encode([['t' => time(), 'type' => 'start', 'detail' => $surface]]),
            'started'   => $now,
            'last_seen' => $now,
        ]);
        return self::find((int)$wpdb->insert_id) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE token = %s', $token), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Append one recorded chunk. Chunks must arrive in order; a repeat of the
     * last chunk is accepted and ignored so a retried upload is harmless.
     *
     * @throws \DomainException
     */
    public static function appendChunk(array $session, int $part, int $seq, string $bytes, string $mime): array
    {
        if ($session['status'] !== 'active' || !$session['recording']) {
            throw new \DomainException(__('This session is not recording.', 'sepo-lms'), 409);
        }
        $len = strlen($bytes);
        if ($len === 0 || $len > self::MAX_CHUNK) {
            throw new \DomainException(__('Recording chunk rejected.', 'sepo-lms'), 413);
        }
        if ($session['bytes'] + $len > self::MAX_SESSION) {
            throw new \DomainException(__('The recording has reached its size limit.', 'sepo-lms'), 413);
        }
        $part = max(1, min(99, $part));
        $state = self::partState($session);
        $expected = $state[$part] ?? 0;
        if ($seq < $expected) {
            return ['bytes' => $session['bytes'], 'duplicate' => true];
        }
        if ($seq !== $expected) {
            throw new \DomainException(__('Recording chunk out of order.', 'sepo-lms'), 409);
        }

        $rel = $session['file'] . '-' . $part . '.webm';
        $dir = Storage::dir(dirname($rel));
        $path = $dir . '/' . basename($rel);
        $fh = fopen($path, 'ab'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ($fh === false) {
            throw new \DomainException(__('The server could not save the recording.', 'sepo-lms'), 500);
        }
        flock($fh, LOCK_EX);
        fwrite($fh, $bytes); // phpcs:ignore WordPress.WP.AlternativeFunctions
        flock($fh, LOCK_UN);
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions

        $state[$part] = $seq + 1;
        global $wpdb;
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'UPDATE ' . self::table() . ' SET bytes = bytes + %d, chunks = chunks + 1, parts = GREATEST(parts, %d), part_state = %s, mime = %s, last_seen = %s WHERE id = %d',
            $len,
            $part,
            (string)wp_json_encode($state),
            $mime !== '' ? substr($mime, 0, 60) : 'video/webm',
            current_time('mysql', true),
            $session['id']
        ));
        return ['bytes' => $session['bytes'] + $len, 'duplicate' => false];
    }

    /** Next expected chunk number per part. @return array<int,int> */
    private static function partState(array $session): array
    {
        $state = json_decode((string)($session['part_state'] ?? ''), true);
        $out = [];
        foreach (is_array($state) ? $state : [] as $part => $next) {
            $out[(int)$part] = (int)$next;
        }
        return $out;
    }

    /**
     * Log an event. Returns the violation count and whether the limit is
     * now exceeded.
     *
     * @return array{violations:int,limit:int,exceeded:bool}
     */
    public static function event(array $session, string $type, string $detail): array
    {
        $type = sanitize_key($type);
        $events = $session['events'];
        if (count($events) < self::MAX_EVENTS) {
            $events[] = ['t' => time(), 'type' => $type, 'detail' => mb_substr(sanitize_text_field($detail), 0, 120)];
        }
        $violations = $session['violations'] + (in_array($type, self::VIOLATIONS, true) ? 1 : 0);
        global $wpdb;
        $wpdb->update(self::table(), [ // phpcs:ignore WordPress.DB
            'events'     => (string)wp_json_encode($events),
            'violations' => $violations,
            'last_seen'  => current_time('mysql', true),
        ], ['id' => $session['id']]);
        $limit = self::settingsFor((int)$session['item_id'])['leaves'];
        return ['violations' => $violations, 'limit' => $limit, 'exceeded' => $limit > 0 && $violations > $limit];
    }

    public static function end(array $session, string $reason, bool $autoSubmitted = false): void
    {
        if ($session['status'] !== 'active') {
            return;
        }
        $events = $session['events'];
        $events[] = ['t' => time(), 'type' => 'end', 'detail' => sanitize_key($reason)];
        global $wpdb;
        $wpdb->update(self::table(), [ // phpcs:ignore WordPress.DB
            'status'         => 'ended',
            'events'         => (string)wp_json_encode($events),
            'auto_submitted' => $autoSubmitted || $session['auto_submitted'] ? 1 : 0,
            'ended'          => current_time('mysql', true),
        ], ['id' => $session['id']]);
    }

    /**
     * Check the session a submission refers to. Returns the session ID (0
     * when the item is not proctored).
     *
     * @throws \DomainException
     */
    public static function verifyForSubmit(int $userId, int $itemId, string $token, array $rules): int
    {
        if (!self::required($rules)) {
            return 0;
        }
        $session = self::findByToken($token);
        if ($session === null || $session['user_id'] !== $userId || $session['item_id'] !== $itemId) {
            throw new \DomainException(__('This work is proctored. Start the proctored session before handing in.', 'sepo-lms'), 403);
        }
        if ($session['status'] === 'abandoned') {
            throw new \DomainException(__('This proctored session was closed. Reload the page and start again.', 'sepo-lms'), 403);
        }
        if ($rules['record'] && ($session['bytes'] <= 0 || !$session['recording'])) {
            throw new \DomainException(__('Your screen recording did not reach the server. Keep sharing your screen and try again.', 'sepo-lms'), 403);
        }
        return (int)$session['id'];
    }

    /** Mark a session as the one that produced a submission, and close it. */
    public static function attach(int $sessionId, bool $autoSubmitted): void
    {
        $s = $sessionId > 0 ? self::find($sessionId) : null;
        if ($s !== null) {
            self::end($s, $autoSubmitted ? 'auto_submitted' : 'submitted', $autoSubmitted);
        }
    }

    /**
     * Staff list.
     *
     * @param array<string,mixed> $args course_ids, item, user, flagged
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $args, int $perPage = 25, int $page = 1): array
    {
        global $wpdb;
        $courses = array_map('intval', (array)($args['course_ids'] ?? []));
        if ($courses === []) {
            return ['rows' => [], 'total' => 0];
        }
        $where = ['p.course_id IN (' . implode(',', array_fill(0, count($courses), '%d')) . ')'];
        $params = $courses;
        if (!empty($args['item'])) {
            $where[] = 'p.item_id = %d';
            $params[] = (int)$args['item'];
        }
        if (!empty($args['user'])) {
            $where[] = 'p.user_id = %d';
            $params[] = (int)$args['user'];
        }
        if (!empty($args['flagged'])) {
            $where[] = '(p.violations > 0 OR p.auto_submitted = 1)';
        }
        $w = implode(' AND ', $where);
        $t = self::table();
        $from = "FROM {$t} p LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id WHERE {$w}";
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$from}", $params)); // phpcs:ignore WordPress.DB
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT p.*, u.display_name, u.user_email {$from} ORDER BY p.started DESC LIMIT %d OFFSET %d",
            array_merge($params, [$perPage, max(0, ($page - 1) * $perPage)])
        ), ARRAY_A) ?: [];
        return ['rows' => array_map([self::class, 'hydrate'], $rows), 'total' => $total];
    }

    /** Recording parts that exist on disk, as signed URLs. @return list<array{part:int,url:string,size:int}> */
    public static function recordingParts(array $session): array
    {
        $out = [];
        if ($session['file'] === '') {
            return $out;
        }
        for ($p = 1; $p <= max(1, (int)$session['parts']); $p++) {
            $path = Storage::resolve($session['file'] . '-' . $p . '.webm');
            if ($path !== '') {
                $out[] = ['part' => $p, 'url' => Downloads::url('rec', (int)$session['id'], $p), 'size' => (int)filesize($path)];
            }
        }
        return $out;
    }

    /** Human label for an event type. */
    public static function eventLabel(string $type): string
    {
        return match ($type) {
            'start'           => __('Session started', 'sepo-lms'),
            'share_started'   => __('Screen sharing started', 'sepo-lms'),
            'share_stopped'   => __('Screen sharing stopped', 'sepo-lms'),
            'fullscreen_enter'=> __('Entered full screen', 'sepo-lms'),
            'fullscreen_exit' => __('Left full screen', 'sepo-lms'),
            'fullscreen_lost' => __('Full screen closed (not counted while the learner can return)', 'sepo-lms'),
            'fullscreen_back' => __('Returned to full screen', 'sepo-lms'),
            'file_picker_open'   => __('Opened the file chooser', 'sepo-lms'),
            'file_picker_closed' => __('Closed the file chooser', 'sepo-lms'),
            'leave_asked'     => __('Asked to leave; chose to stay', 'sepo-lms'),
            'left_page'       => __('Left the page after confirming', 'sepo-lms'),
            'leave'           => __('Left the page (tab or window switch)', 'sepo-lms'),
            'return'          => __('Came back to the page', 'sepo-lms'),
            'copy'            => __('Copied text', 'sepo-lms'),
            'paste'           => __('Pasted text', 'sepo-lms'),
            'print_screen'    => __('Pressed Print Screen', 'sepo-lms'),
            'warning'         => __('Warning shown', 'sepo-lms'),
            'auto_submit'     => __('Submitted automatically (limit exceeded)', 'sepo-lms'),
            'submit'          => __('Handed in', 'sepo-lms'),
            'end'             => __('Session ended', 'sepo-lms'),
            'recording_deleted' => __('Recording deleted', 'sepo-lms'),
            default           => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    public static function deleteSession(array $session): void
    {
        global $wpdb;
        self::deleteFiles($session);
        $wpdb->delete(self::table(), ['id' => $session['id']]); // phpcs:ignore WordPress.DB
    }

    public static function deleteForItem(int $itemId): void
    {
        foreach (self::rowsWhere('item_id = %d', $itemId) as $s) {
            self::deleteSession($s);
        }
    }

    public static function deleteForUser(int $userId): int
    {
        $n = 0;
        foreach (self::rowsWhere('user_id = %d', $userId) as $s) {
            self::deleteSession($s);
            $n++;
        }
        return $n;
    }

    /**
     * Delete a session's screen recording and nothing else: the session, its
     * timeline and the submission it belongs to all stay. The deletion is
     * written into the timeline, with who did it, so the gap is explained.
     *
     * @param string $by who deleted it, shown in the timeline
     * @return bool false when there was no recording to delete
     */
    public static function deleteRecording(array $session, string $by): bool
    {
        if ($session['file'] === '' && (int)$session['bytes'] === 0) {
            return false;
        }
        self::deleteFiles($session);
        $events = $session['events'];
        // Recorded even when the timeline is full: it explains why the video is missing.
        $events[] = ['t' => time(), 'type' => 'recording_deleted', 'detail' => mb_substr(sanitize_text_field($by), 0, 120)];
        global $wpdb;
        $wpdb->update(self::table(), [ // phpcs:ignore WordPress.DB
            'file'       => '',
            'bytes'      => 0,
            'parts'      => 0,
            'part_state' => '',
            'events'     => (string)wp_json_encode($events),
        ], ['id' => $session['id']]);
        return true;
    }

    /** The timeline entry for a deleted recording, if there is one. @return array{t:int,detail:string}|null */
    public static function recordingDeletion(array $session): ?array
    {
        foreach (array_reverse($session['events']) as $e) {
            if (($e['type'] ?? '') === 'recording_deleted') {
                return ['t' => (int)($e['t'] ?? 0), 'detail' => (string)($e['detail'] ?? '')];
            }
        }
        return null;
    }

    /** Delete recordings (not the session log) older than the retention period. */
    public static function purgeOldRecordings(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $n = 0;
        foreach (self::rowsWhere("started < %s AND file <> ''", $cutoff) as $s) {
            if (self::deleteRecording($s, __('retention period', 'sepo-lms'))) {
                $n++;
            }
        }
        // Sessions left open (closed tab, lost connection) are closed after a day.
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB
            'UPDATE ' . self::table() . " SET status = 'abandoned', ended = last_seen WHERE status = 'active' AND last_seen < %s",
            gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
        return $n;
    }

    /** @return list<array<string,mixed>> */
    private static function rowsWhere(string $where, string|int $value): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE ' . $where, $value), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        return array_map([self::class, 'hydrate'], $rows);
    }

    private static function deleteFiles(array $session): void
    {
        if ($session['file'] === '') {
            return;
        }
        for ($p = 1; $p <= max(1, (int)$session['parts']); $p++) {
            Storage::delete($session['file'] . '-' . $p . '.webm');
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function hydrate(array $r): array
    {
        foreach (['id', 'user_id', 'item_id', 'course_id', 'bytes', 'chunks', 'parts', 'violations'] as $k) {
            $r[$k] = (int)($r[$k] ?? 0);
        }
        $r['recording'] = !empty($r['recording']);
        $r['auto_submitted'] = !empty($r['auto_submitted']);
        $events = json_decode((string)($r['events'] ?? '[]'), true);
        $r['events'] = is_array($events) ? $events : [];
        $r['file'] = (string)($r['file'] ?? '');
        return $r;
    }
}
