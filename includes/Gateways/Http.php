<?php

declare(strict_types=1);

namespace SepoLms\Gateways;

/**
 * JSON over wp_remote_request, with errors turned into exceptions.
 *
 * A failed request throws HttpError, which carries the HTTP status (0 when
 * the request never got an answer). That lets a caller tell "the provider
 * refused this, nothing was created" apart from "we don't know what
 * happened" — and only forget a payment in the first case.
 */
final class Http
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $extra   extra wp_remote_request args
     * @return array<string|int,mixed>
     */
    public static function request(string $method, string $url, array $headers = [], string|array|null $body = null, array $extra = []): array
    {
        $args = array_merge([
            'method'  => $method,
            'timeout' => 30,
            'headers' => $headers,
        ], $extra);
        if ($body !== null) {
            $args['body'] = $body;
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            throw new HttpError($res->get_error_message(), 0);
        }
        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) ? self::message($data) : '';
            if ($msg === '' && $raw !== '') {
                $msg = mb_substr(wp_strip_all_tags($raw), 0, 300);
            }
            /* translators: 1: HTTP status code, 2: error message */
            throw new HttpError(sprintf(__('The payment service answered %1$d. %2$s', 'sepo-lms'), $code, $msg), $code);
        }
        return is_array($data) ? $data : [];
    }

    /**
     * The error text out of the shapes Stripe, PayPal and pawaPay use.
     *
     * @param array<string|int,mixed> $data
     */
    private static function message(array $data): string
    {
        // pawaPay nests the detail: {"status":"REJECTED","failureReason":{...}}
        $src = isset($data['failureReason']) && is_array($data['failureReason']) ? $data['failureReason'] : $data;
        $parts = [];
        foreach (['failureCode', 'errorCode'] as $k) {
            if (!empty($src[$k]) && is_scalar($src[$k])) {
                $parts[] = (string)$src[$k];
                break;
            }
        }
        $msg = $src['failureMessage'] ?? $src['errorMessage'] ?? $data['error']['message'] ?? $src['message'] ?? $data['error_description'] ?? '';
        if (is_scalar($msg) && (string)$msg !== '') {
            $parts[] = (string)$msg;
        }
        if ($parts === [] && isset($data['details'][0]['description'])) {
            $parts[] = (string)$data['details'][0]['description'];
        }
        return mb_substr(implode(' — ', $parts), 0, 400);
    }
}
