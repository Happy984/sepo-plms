<?php

declare(strict_types=1);

namespace SepoLms\Gateways;

use SepoLms\Payments;
use SepoLms\Settings;

/**
 * PayPal Checkout (Orders v2): create an order, send the learner to PayPal
 * to approve it, capture it when they come back.
 */
final class PayPal implements Gateway
{
    public const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];
    private const NO_DECIMALS = ['HUF', 'JPY', 'TWD'];

    public function ready(): bool
    {
        return (string)Settings::get('paypal_client') !== '' && (string)Settings::get('paypal_secret') !== '';
    }

    private function base(): string
    {
        return Settings::get('paypal_sandbox') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function token(): string
    {
        $key = 'slms_paypal_token_' . md5($this->base() . (string)Settings::get('paypal_client'));
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $data = Http::request('POST', $this->base() . '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode((string)Settings::get('paypal_client') . ':' . (string)Settings::get('paypal_secret')),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');
        $token = (string)($data['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException(__('PayPal did not issue an access token. Check the client ID and secret.', 'sepo-lms'));
        }
        set_transient($key, $token, max(60, (int)($data['expires_in'] ?? 3600) - 120));
        return $token;
    }

    /** @return array<string,string> */
    private function headers(string $requestId = ''): array
    {
        $h = ['Authorization' => 'Bearer ' . $this->token(), 'Content-Type' => 'application/json'];
        if ($requestId !== '') {
            $h['PayPal-Request-Id'] = $requestId;
        }
        return $h;
    }

    public static function value(float $amount, string $currency): string
    {
        return in_array(strtoupper($currency), self::NO_DECIMALS, true)
            ? (string)(int)round($amount)
            : number_format($amount, 2, '.', '');
    }

    public function start(array $enrollment, array $method, string $phone): array
    {
        $cur = strtoupper((string)$enrollment['currency']);
        if (!in_array($cur, self::CURRENCIES, true)) {
            /* translators: %s: currency code */
            throw new \RuntimeException(sprintf(__('PayPal cannot take payments in %s. Choose another payment method.', 'sepo-lms'), $cur));
        }
        $ref = (string)$enrollment['reference'];
        $return = Payments::returnUrl($ref, 'paypal');
        $body = [
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $ref,
                'custom_id'    => $ref,
                'description'  => mb_substr((string)$enrollment['title'], 0, 120),
                'amount'       => ['currency_code' => $cur, 'value' => self::value((float)$enrollment['amount'], $cur)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url'          => $return,
                'cancel_url'          => $return . '&cancelled=1',
                'user_action'         => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name'          => mb_substr((string)Settings::get('business_name'), 0, 120),
            ]]],
        ];
        $data = Http::request('POST', $this->base() . '/v2/checkout/orders', $this->headers('create-' . $ref . '-' . time()), (string)wp_json_encode($body));
        $url = '';
        foreach ((array)($data['links'] ?? []) as $l) {
            if (in_array(($l['rel'] ?? ''), ['payer-action', 'approve'], true)) {
                $url = (string)$l['href'];
                break;
            }
        }
        $id = (string)($data['id'] ?? '');
        if ($url === '' || $id === '') {
            throw new \RuntimeException(__('PayPal did not return an approval page.', 'sepo-lms'));
        }
        return ['url' => $url, 'ref' => $id, 'status' => (string)($data['status'] ?? 'CREATED'), 'poll' => false, 'message' => ''];
    }

    public function check(array $enrollment): array
    {
        $id = (string)$enrollment['gateway_ref'];
        if ($id === '') {
            return ['state' => 'pending', 'status' => ''];
        }
        $order = Http::request('GET', $this->base() . '/v2/checkout/orders/' . rawurlencode($id), $this->headers());
        $status = (string)($order['status'] ?? '');

        if ($status === 'APPROVED') {
            // The learner approved it; take the money. The request id makes a
            // repeated capture (return page and sweep at once) harmless.
            $order = Http::request('POST', $this->base() . '/v2/checkout/orders/' . rawurlencode($id) . '/capture', $this->headers('capture-' . $id), '{}');
            $status = (string)($order['status'] ?? '');
        }

        if ($status === 'COMPLETED') {
            $unit = $order['purchase_units'][0] ?? [];
            $cap = $unit['payments']['captures'][0] ?? [];
            $amount = $cap['amount'] ?? ($unit['amount'] ?? []);
            $sameRef = (string)($unit['custom_id'] ?? $cap['custom_id'] ?? $unit['reference_id'] ?? '') === (string)$enrollment['reference'];
            $sameAmount = strtoupper((string)($amount['currency_code'] ?? '')) === strtoupper((string)$enrollment['currency'])
                && abs((float)($amount['value'] ?? -1) - (float)$enrollment['amount']) < 0.01;
            $capStatus = (string)($cap['status'] ?? 'COMPLETED');
            if ($sameRef && $sameAmount && $capStatus === 'COMPLETED') {
                return ['state' => 'paid', 'status' => 'COMPLETED'];
            }
            return ['state' => 'pending', 'status' => $capStatus];
        }
        if ($status === 'VOIDED') {
            return ['state' => 'failed', 'status' => $status];
        }
        return ['state' => 'pending', 'status' => $status];
    }
}
