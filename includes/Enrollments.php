<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Enrolment records.
 *
 * Status is one of:
 *   active   enrolled in a free course
 *   pending  enrolled, waiting for payment
 *   paid     payment confirmed
 *
 * A paid course's lessons open only when the status is "paid".
 */
final class Enrollments
{
    public const STATUSES = ['pending', 'active', 'paid'];

    /** @var array<string,array<string,mixed>|null> */
    private static array $byCourseUser = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_enrollments';
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByReference(string $ref): ?array
    {
        global $wpdb;
        if ($ref === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reference = %s', $ref), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByGatewayRef(string $gateway, string $gatewayRef): ?array
    {
        global $wpdb;
        if ($gatewayRef === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE gateway = %s AND gateway_ref = %s', $gateway, $gatewayRef), ARRAY_A); // phpcs:ignore WordPress.DB
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function forCourseUser(int $courseId, int $userId): ?array
    {
        if ($courseId <= 0 || $userId <= 0) {
            return null;
        }
        $k = $courseId . ':' . $userId;
        if (!array_key_exists($k, self::$byCourseUser)) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE course_id = %d AND user_id = %d', $courseId, $userId), ARRAY_A); // phpcs:ignore WordPress.DB
            self::$byCourseUser[$k] = is_array($row) ? self::hydrate($row) : null;
        }
        return self::$byCourseUser[$k];
    }

    public static function status(int $courseId, int $userId): ?string
    {
        $row = self::forCourseUser($courseId, $userId);
        return $row === null ? null : (string)$row['status'];
    }

    /**
     * Whether this user can open the course's lessons: enrolled, and either
     * the course is free or payment is confirmed.
     */
    public static function hasAccess(int $courseId, int $userId): bool
    {
        $status = self::status($courseId, $userId);
        if ($status === null) {
            return false;
        }
        if ($status === 'paid') {
            return true;
        }
        $course = Course::get($courseId);
        return $course !== null && $course['free'];
    }

    /**
     * Learners with access to a course (same rule as hasAccess), by name.
     *
     * @return list<array{user_id:int,name:string,email:string}>
     */
    public static function learners(int $courseId): array
    {
        global $wpdb;
        $course = Course::get($courseId);
        if ($course === null) {
            return [];
        }
        $sql = 'SELECT e.user_id, e.name, e.email, u.display_name, u.user_email FROM ' . self::table() . " e LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id WHERE e.course_id = %d"
            . ($course['free'] ? '' : " AND e.status = 'paid'") . ' ORDER BY e.name ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $courseId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['user_id'] <= 0 || $r['display_name'] === null) {
                continue;
            }
            $out[] = [
                'user_id' => (int)$r['user_id'],
                'name'    => (string)($r['name'] !== '' ? $r['name'] : $r['display_name']),
                'email'   => (string)($r['user_email'] ?: $r['email']),
            ];
        }
        return $out;
    }

    public static function count(int $courseId): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE course_id = %d', $courseId)); // phpcs:ignore WordPress.DB
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created DESC', $userId), ARRAY_A); // phpcs:ignore WordPress.DB
        return array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []);
    }

    /**
     * Admin listing.
     *
     * @param array{status?:string,course?:int,search?:string,orderby?:string,order?:string,from?:string,to?:string} $args
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $args, int $perPage = 20, int $page = 1): array
    {
        global $wpdb;
        [$where, $params] = self::where($args);

        $cols = ['created', 'name', 'email', 'title', 'amount', 'status', 'level'];
        $orderby = in_array($args['orderby'] ?? '', $cols, true) ? $args['orderby'] : 'created';
        $order = strtoupper((string)($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $sqlCount = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where;
        $total = (int)($params === [] ? $wpdb->get_var($sqlCount) : $wpdb->get_var($wpdb->prepare($sqlCount, $params))); // phpcs:ignore WordPress.DB

        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where . " ORDER BY {$orderby} {$order}, id DESC";
        if ($perPage > 0) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $perPage, max(0, ($page - 1) * $perPage));
        }
        $rows = $params === [] ? $wpdb->get_results($sql, ARRAY_A) : $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A); // phpcs:ignore WordPress.DB

        return ['rows' => array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []), 'total' => $total];
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0:string,1:list<mixed>}
     */
    private static function where(array $args): array
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        if (!empty($args['status']) && in_array($args['status'], self::STATUSES, true)) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['course'])) {
            $where[] = 'course_id = %d';
            $params[] = (int)$args['course'];
        }
        if (!empty($args['from'])) {
            $where[] = 'created >= %s';
            $params[] = $args['from'] . ' 00:00:00';
        }
        if (!empty($args['to'])) {
            $where[] = 'created <= %s';
            $params[] = $args['to'] . ' 23:59:59';
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string)$args['search']) . '%';
            $where[] = '(name LIKE %s OR email LIKE %s OR title LIKE %s OR reference LIKE %s OR national_id LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return array<string,int> */
    public static function statusCounts(): array
    {
        global $wpdb;
        $out = array_fill_keys(self::STATUSES, 0);
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A); // phpcs:ignore WordPress.DB
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Insert a new enrolment.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(array $data): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $row = self::clean($data) + [
            'reference' => self::newReference(),
            'created'   => $now,
            'updated'   => $now,
        ];
        if ($row['status'] === 'paid') {
            $row['paid_at'] = $now;
        }
        $ok = $wpdb->insert(self::table(), $row); // phpcs:ignore WordPress.DB
        if ($ok === false) {
            throw new \RuntimeException(__('You are already enrolled in this course.', 'sepo-lms'));
        }
        self::$byCourseUser = [];
        return self::find((int)$wpdb->insert_id) ?? [];
    }

    /**
     * Update the payment details of an enrolment still waiting for payment
     * (a learner who abandoned a checkout and is trying again).
     *
     * @param array<string,mixed> $data
     */
    public static function refresh(int $id, array $data): void
    {
        global $wpdb;
        $row = self::clean($data);
        unset($row['course_id'], $row['user_id']);
        $row['updated'] = current_time('mysql', true);
        $row['gateway_ref'] = '';
        $row['gateway_status'] = '';
        $wpdb->update(self::table(), $row, ['id' => $id, 'status' => 'pending']); // phpcs:ignore WordPress.DB
        self::$byCourseUser = [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function clean(array $data): array
    {
        $s = static fn(string $k, int $max): string => mb_substr(trim(sanitize_text_field((string)($data[$k] ?? ''))), 0, $max);
        $status = (string)($data['status'] ?? 'pending');
        return [
            'course_id'       => (int)($data['course_id'] ?? 0),
            'user_id'         => (int)($data['user_id'] ?? 0),
            'title'           => $s('title', 255),
            'name'            => $s('name', 120),
            'email'           => mb_substr(sanitize_email((string)($data['email'] ?? '')), 0, 160),
            'national_id'     => $s('national_id', 60),
            'gender'          => $s('gender', 30),
            'location'        => $s('location', 160),
            'dob'             => $s('dob', 20),
            'phone'           => $s('phone', 30),
            'level'           => $s('level', 60),
            'status'          => in_array($status, self::STATUSES, true) ? $status : 'pending',
            'method_id'       => $s('method_id', 60),
            'method'          => $s('method', 120),
            'method_type'     => $s('method_type', 20),
            'currency'        => strtoupper($s('currency', 3)),
            'base_price'      => round((float)($data['base_price'] ?? 0), 2),
            'discount_rate'   => round((float)($data['discount_rate'] ?? 0), 2),
            'discount_label'  => $s('discount_label', 60),
            'discount_amount' => round((float)($data['discount_amount'] ?? 0), 2),
            'unit_price'      => round((float)($data['unit_price'] ?? 0), 2),
            'tax_rate'        => round((float)($data['tax_rate'] ?? 0), 2),
            'tax_type'        => $s('tax_type', 30),
            'tax_country'     => $s('tax_country', 60),
            'tax_amount'      => round((float)($data['tax_amount'] ?? 0), 2),
            'quantity'        => max(1, (int)($data['quantity'] ?? 1)),
            'amount'          => round((float)($data['amount'] ?? 0), 2),
            'gateway'         => $s('gateway', 20),
        ];
    }

    public static function setGateway(int $id, string $gatewayRef, string $gatewayStatus = ''): void
    {
        global $wpdb;
        $wpdb->update(self::table(), [ // phpcs:ignore WordPress.DB
            'gateway_ref'    => mb_substr($gatewayRef, 0, 191),
            'gateway_status' => mb_substr($gatewayStatus, 0, 40),
            'updated'        => current_time('mysql', true),
        ], ['id' => $id]);
        self::$byCourseUser = [];
    }

    public static function setGatewayStatus(int $id, string $gatewayStatus): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['gateway_status' => mb_substr($gatewayStatus, 0, 40), 'updated' => current_time('mysql', true)], ['id' => $id]); // phpcs:ignore WordPress.DB
    }

    /**
     * Change status. Moving to "paid" goes through settle() so the receipt
     * and the action fire exactly once.
     */
    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(__('Unknown status.', 'sepo-lms'));
        }
        if ($status === 'paid') {
            return self::settle($id) !== null || (self::find($id)['status'] ?? '') === 'paid';
        }
        global $wpdb;
        $n = $wpdb->update(self::table(), ['status' => $status, 'updated' => current_time('mysql', true)], ['id' => $id]); // phpcs:ignore WordPress.DB
        self::$byCourseUser = [];
        return $n !== false && self::find($id) !== null;
    }

    /**
     * Mark an enrolment paid — idempotent.
     *
     * The UPDATE only matches a row that is not already paid, so when a
     * webhook, the return page and the hourly sweep all confirm the same
     * payment, only the first one gets a row back and only that one sends
     * the receipt.
     *
     * @return array<string,mixed>|null The enrolment if this call settled it.
     */
    public static function settle(int $id, string $gatewayRef = '', string $gatewayStatus = ''): ?array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $set = 'status = %s, paid_at = %s, updated = %s';
        $params = ['paid', $now, $now];
        if ($gatewayRef !== '') {
            $set .= ', gateway_ref = %s';
            $params[] = mb_substr($gatewayRef, 0, 191);
        }
        if ($gatewayStatus !== '') {
            $set .= ', gateway_status = %s';
            $params[] = mb_substr($gatewayStatus, 0, 40);
        }
        $params[] = $id;
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET {$set} WHERE id = %d AND status <> 'paid'", $params)); // phpcs:ignore WordPress.DB
        self::$byCourseUser = [];
        if ((int)$n !== 1) {
            return null;
        }
        $row = self::find($id);
        if ($row === null) {
            return null;
        }
        Mailer::receipt($row);
        /**
         * Fires once when an enrolment's payment is confirmed.
         *
         * @param array<string,mixed> $row The enrolment.
         */
        do_action('sepo_lms_enrollment_paid', $row);
        return $row;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $row = self::find($id);
        if ($row === null) {
            return false;
        }
        $n = $wpdb->delete(self::table(), ['id' => $id]); // phpcs:ignore WordPress.DB
        self::$byCourseUser = [];
        if ($n) {
            do_action('sepo_lms_enrollment_removed', $row);
        }
        return (bool)$n;
    }

    public static function deleteForCourse(int $courseId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['course_id' => $courseId]); // phpcs:ignore WordPress.DB
        self::$byCourseUser = [];
    }

    /**
     * Gateway enrolments still waiting, for the hourly sweep.
     *
     * @return list<array<string,mixed>>
     */
    public static function pendingGateway(int $maxAgeDays = 3, int $limit = 50): array
    {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - $maxAgeDays * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB
            'SELECT * FROM ' . self::table() . " WHERE status = 'pending' AND gateway <> '' AND gateway_ref <> '' AND updated >= %s ORDER BY updated ASC LIMIT %d",
            $since,
            $limit
        ), ARRAY_A);
        return array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []);
    }

    private static function newReference(): string
    {
        return 'ENR-' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'course_id', 'user_id', 'quantity'] as $k) {
            $row[$k] = (int)$row[$k];
        }
        foreach (['base_price', 'discount_rate', 'discount_amount', 'unit_price', 'tax_rate', 'tax_amount', 'amount'] as $k) {
            $row[$k] = (float)$row[$k];
        }
        return $row;
    }
}
