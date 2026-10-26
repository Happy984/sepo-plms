<?php

declare(strict_types=1);

namespace SepoLms\Api;

use SepoLms\Rest;
use WP_Error;
use WP_REST_Response;

/**
 * Shared helpers for the classroom REST controllers.
 */
abstract class Base
{
    protected static function route(string $path, string $methods, callable $cb, ?callable $perm = null): void
    {
        register_rest_route(Rest::NS, $path, [
            'methods'             => $methods,
            'callback'            => static function ($req) use ($cb) {
                try {
                    return $cb($req);
                } catch (\DomainException $e) {
                    $code = (int)$e->getCode();
                    return self::error($e->getMessage(), $code >= 400 && $code < 600 ? $code : 400);
                }
            },
            'permission_callback' => $perm ?? static fn(): bool => is_user_logged_in(),
        ]);
    }

    protected static function error(string $message, int $status): WP_Error
    {
        return new WP_Error('sepo_lms', $message, ['status' => $status]);
    }

    /** @param array<string,mixed> $data */
    protected static function ok(array $data = []): WP_REST_Response
    {
        return new WP_REST_Response($data, 200);
    }

    protected static function text(mixed $v, int $max = 0): string
    {
        $s = is_scalar($v) ? sanitize_textarea_field((string)$v) : '';
        return $max > 0 ? mb_substr($s, 0, $max) : $s;
    }
}
