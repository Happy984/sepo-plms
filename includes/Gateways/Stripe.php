<?php

declare(strict_types=1);

namespace SepoLms\Gateways;

use SepoLms\Payments;
use SepoLms\Settings;

/**
 * Stripe Checkout (hosted payment page).
 */
final class Stripe implements Gateway
{
    private const API = 'https://api.stripe.com/v1';

    /** Currencies Stripe counts in whole units. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function ready(): bool
    {
        return (string)Settings::get('stripe_secret') !== '';
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . (string)Settings::get('stripe_secret'),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ];
    }

    public static function minorUnits(float $amount, string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? (int)round($amount) : (int)round($amount * 100);
    }

    public function start(array $enrollment, array $method, string $phone): array
    {
        $ref = (string)$enrollment['reference'];
        $return = Payments::returnUrl($ref, 'stripe');
        $body = [
            'mode'                => 'payment',
            'client_reference_id' => $ref,
            'customer_email'      => (string)$enrollment['email'],
            // {CHECKOUT_SESSION_ID} must reach Stripe unencoded; it fills it in.
            'success_url'         => $return . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'          => $return . '&cancelled=1',
            'metadata'            => ['reference' => $ref, 'course_id' => (string)$enrollment['course_id']],
            'line_items'          => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower((string)$enrollment['currency']),
                    'unit_amount'  => self::minorUnits((float)$enrollment['amount'], (string)$enrollment['currency']),
                    'product_data' => ['name' => mb_substr((string)$enrollment['title'], 0, 250) ?: $ref],
                ],
            ]],
        ];
        $data = Http::request('POST', self::API . '/checkout/sessions', $this->headers(), http_build_query($body, '', '&'));
        $url = (string)($data['url'] ?? '');
        $id = (string)($data['id'] ?? '');
        if ($url === '' || $id === '') {
            throw new \RuntimeException(__('Stripe did not return a checkout page.', 'sepo-lms'));
        }
        return ['url' => $url, 'ref' => $id, 'status' => 'open', 'poll' => false, 'message' => ''];
    }

    public function check(array $enrollment): array
    {
        $id = (string)$enrollment['gateway_ref'];
        if ($id === '') {
            return ['state' => 'pending', 'status' => ''];
        }
        $data = Http::request('GET', self::API . '/checkout/sessions/' . rawurlencode($id), $this->headers());
        $paymentStatus = (string)($data['payment_status'] ?? '');
        $status = (string)($data['status'] ?? '');

        // The session must be for this enrolment and for the full amount.
        $sameRef = (string)($data['client_reference_id'] ?? '') === (string)$enrollment['reference'];
        $sameAmount = (int)($data['amount_total'] ?? -1) === self::minorUnits((float)$enrollment['amount'], (string)$enrollment['currency'])
            && strtoupper((string)($data['currency'] ?? '')) === strtoupper((string)$enrollment['currency']);

        if ($paymentStatus === 'paid' && $sameRef && $sameAmount) {
            return ['state' => 'paid', 'status' => 'paid'];
        }
        if ($status === 'expired') {
            return ['state' => 'failed', 'status' => 'expired'];
        }
        return ['state' => 'pending', 'status' => $paymentStatus !== '' ? $paymentStatus : $status];
    }

    /** Expire an abandoned Checkout Session so it cannot be paid as well as the new one. */
    public function abandon(array $enrollment): void
    {
        $id = (string)$enrollment['gateway_ref'];
        if ($id === '') {
            return;
        }
        try {
            Http::request('POST', self::API . '/checkout/sessions/' . rawurlencode($id) . '/expire', $this->headers(), '');
        } catch (\Throwable $e) {
            // Already complete or expired: nothing to close.
        }
    }

    /**
     * Check a webhook's Stripe-Signature header against the endpoint secret.
     * The event body is only used to learn which session to re-fetch.
     */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $t = 0;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = (int)$v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if ($t <= 0 || $sigs === [] || abs(time() - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }
        return false;
    }
}
