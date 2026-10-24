<?php

declare(strict_types=1);

namespace SepoLms\Gateways;

use SepoLms\Enrollments;
use SepoLms\Helpers;
use SepoLms\Payments;
use SepoLms\Settings;

/**
 * pawaPay mobile money, Merchant API v2.
 *
 * Two ways in, both settled the same way:
 *
 *   Hosted checkout (the payment method has no network set)
 *     POST /v2/checkouts — the learner is sent to pawaPay's payment page,
 *     picks their network and approves. If an attempt fails they can retry
 *     on the same page; pawaPay tracks every attempt under one checkout.
 *     GET /v2/checkouts/{id} reads the outcome.
 *
 *   Direct deposit (the method names a network, e.g. MTN_MOMO_ZMB)
 *     POST /v2/deposits — the prompt goes straight to the learner's phone
 *     and the course page waits. GET /v2/deposits/{id} reads the outcome.
 *
 * The gateway reference stored on the enrolment is "checkout:<uuid>" or
 * "deposit:<uuid>", so the right status endpoint is always used.
 *
 * Money rules, as in the SepoEngine PawaPayService:
 *   - The id is written to the enrolment BEFORE pawaPay is called, so a
 *     request whose answer is lost can still be looked up and settled.
 *   - Only a status read back from pawaPay settles anything; callback
 *     bodies are just a prompt to look.
 *   - A paid status is accepted only for the full amount in the right
 *     currency.
 */
final class PawaPay implements Gateway
{
    public const CHECKOUT = 'checkout:';
    public const DEPOSIT = 'deposit:';

    public function ready(): bool
    {
        return (string)Settings::get('pawapay_token') !== '';
    }

    private function base(): string
    {
        return untrailingslashit((string)Settings::get(Settings::get('pawapay_sandbox') ? 'pawapay_base_sandbox' : 'pawapay_base_live'));
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string|int,mixed>
     */
    private function send(string $method, string $path, ?array $payload = null): array
    {
        return Http::request($method, $this->base() . $path, [
            'Authorization' => 'Bearer ' . (string)Settings::get('pawapay_token'),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ], $payload !== null ? (string)wp_json_encode($payload) : null, [
            // A cross-continent TLS handshake can be slow; give it room.
            'timeout' => 45,
        ]);
    }

    /** pawaPay allows 4–22 letters, digits and spaces. */
    public static function statement(string $text, string $fallback = 'Course enrolment'): string
    {
        $s = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', $text) ?? '') ?? '');
        $s = trim(mb_substr($s, 0, 22));
        return strlen($s) >= 4 ? $s : $fallback;
    }

    /** Whole amounts go without decimals; some networks reject them. */
    public static function amount(float $amount): string
    {
        return abs($amount - round($amount)) < 0.00001 ? (string)(int)round($amount) : number_format($amount, 2, '.', '');
    }

    public function start(array $enrollment, array $method, string $phone): array
    {
        $id = wp_generate_uuid4();
        $provider = strtoupper(trim((string)($method['correspondent'] ?? '')));
        $msisdn = Helpers::msisdn($phone);
        $ref = ($provider !== '' ? self::DEPOSIT : self::CHECKOUT) . $id;

        // Durable first: if the answer is lost, the sweep can still find it.
        Enrollments::setGateway((int)$enrollment['id'], $ref, 'INITIATING');

        $currency = strtoupper((string)$enrollment['currency']);
        $amount = self::amount((float)$enrollment['amount']);
        $reference = (string)$enrollment['reference'];
        $metadata = [['enrollment' => $reference], ['course' => (string)$enrollment['course_id']]];

        if ($provider !== '') {
            if ($msisdn === '') {
                throw new HttpError(__('A phone number is needed for mobile money.', 'sepo-lms'), 422);
            }
            $res = $this->send('POST', '/v2/deposits', [
                'depositId'         => $id,
                'payer'             => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => $msisdn, 'provider' => $provider]],
                'amount'            => $amount,
                'currency'          => $currency,
                'clientReferenceId' => $reference,
                'customerMessage'   => self::statement((string)Settings::get('business_name')),
                'metadata'          => $metadata,
            ]);
            $status = strtoupper((string)($res['status'] ?? ''));
            if ($status === 'REJECTED') {
                throw new HttpError(sprintf(
                    /* translators: %s: reason given by pawaPay */
                    __('The mobile money request was refused: %s', 'sepo-lms'),
                    self::reason($res)
                ), 400);
            }
            return [
                'url'     => '',
                'ref'     => $ref,
                'status'  => $status !== '' ? $status : 'ACCEPTED',
                'poll'    => true,
                'message' => __('Check your phone and approve the payment prompt. This page will update once it goes through.', 'sepo-lms'),
            ];
        }

        $payload = [
            'checkoutId'        => $id,
            'returnUrl'         => Payments::returnUrl($reference, 'pawapay'),
            'returnMethod'      => 'INSTANT',
            'defaultLanguage'   => 'en',
            'countries'         => [(string)Settings::get('pawapay_country')],
            'expiresAfter'      => 15,
            'amounts'           => [['country' => (string)Settings::get('pawapay_country'), 'currency' => $currency, 'amount' => $amount]],
            'clientReferenceId' => $reference,
            'reason'            => ['en' => self::statement((string)$enrollment['title'])],
            'metadata'          => $metadata,
        ];
        if ($msisdn !== '') {
            // Pre-fill the learner's number; they can still change it.
            $account = ['phoneNumber' => $msisdn, 'allowCustomerToOverride' => true];
            try {
                $guess = $this->send('POST', '/v2/predict-provider', ['phoneNumber' => $msisdn]);
                if (!empty($guess['provider'])) {
                    $account['provider'] = (string)$guess['provider'];
                }
                if (!empty($guess['phoneNumber'])) {
                    $account['phoneNumber'] = (string)$guess['phoneNumber'];
                }
            } catch (\Throwable $e) {
                // Only a convenience: the learner picks the network on the page.
            }
            $payload['payer'] = ['type' => 'MMO', 'accountDetails' => $account];
        }

        $res = $this->send('POST', '/v2/checkouts', $payload);
        $status = strtoupper((string)($res['status'] ?? ''));
        $url = (string)($res['redirectUrl'] ?? '');
        if ($status !== 'ACCEPTED' || $url === '') {
            throw new HttpError(sprintf(
                /* translators: %s: status or reason given by pawaPay */
                __('pawaPay did not accept the checkout: %s', 'sepo-lms'),
                $status === '' ? __('no answer', 'sepo-lms') : self::reason($res)
            ), $status === 'REJECTED' ? 400 : 0);
        }
        return ['url' => $url, 'ref' => $ref, 'status' => 'WAITING_PAYMENT', 'poll' => false, 'message' => ''];
    }

    public function check(array $enrollment): array
    {
        $ref = (string)$enrollment['gateway_ref'];
        if (str_starts_with($ref, self::CHECKOUT)) {
            $res = $this->send('GET', '/v2/checkouts/' . rawurlencode(substr($ref, strlen(self::CHECKOUT))));
            if (strtoupper((string)($res['status'] ?? '')) !== 'FOUND') {
                return ['state' => 'pending', 'status' => 'NOT_FOUND'];
            }
            $data = (array)($res['data'] ?? []);
            $status = strtoupper((string)($data['status'] ?? ''));
            if ($status === 'COMPLETED') {
                return self::verifyAmount($enrollment, self::paidDeposit($data), $status);
            }
            if (in_array($status, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {
                return ['state' => 'failed', 'status' => $status];
            }
            return ['state' => 'pending', 'status' => $status];
        }

        if (str_starts_with($ref, self::DEPOSIT)) {
            $res = $this->send('GET', '/v2/deposits/' . rawurlencode(substr($ref, strlen(self::DEPOSIT))));
            if (strtoupper((string)($res['status'] ?? '')) !== 'FOUND') {
                return ['state' => 'pending', 'status' => 'NOT_FOUND'];
            }
            $data = (array)($res['data'] ?? []);
            $status = strtoupper((string)($data['status'] ?? ''));
            if ($status === 'COMPLETED') {
                return self::verifyAmount($enrollment, $data, $status);
            }
            if ($status === 'FAILED') {
                return ['state' => 'failed', 'status' => $status];
            }
            return ['state' => 'pending', 'status' => $status];
        }

        return ['state' => 'pending', 'status' => ''];
    }

    /**
     * Close a hosted checkout the learner abandoned, so the old payment page
     * cannot be paid as well as the new one.
     *
     * @param array<string,mixed> $enrollment
     */
    public function abandon(array $enrollment): void
    {
        $ref = (string)$enrollment['gateway_ref'];
        if (str_starts_with($ref, self::CHECKOUT)) {
            try {
                $this->send('POST', '/v2/checkouts/' . rawurlencode(substr($ref, strlen(self::CHECKOUT))) . '/expire');
            } catch (\Throwable $e) {
                Payments::log('could not expire pawaPay checkout ' . $ref . ': ' . $e->getMessage());
            }
        }
    }

    /** A direct deposit still waiting on the learner's phone. */
    public static function inFlight(array $enrollment): bool
    {
        return str_starts_with((string)$enrollment['gateway_ref'], self::DEPOSIT)
            && in_array(strtoupper((string)$enrollment['gateway_status']), ['INITIATING', 'ACCEPTED', 'SUBMITTED', 'ENQUEUED', 'PROCESSING', 'IN_RECONCILIATION'], true)
            && strtotime((string)$enrollment['updated'] . ' UTC') > time() - 10 * MINUTE_IN_SECONDS;
    }

    /**
     * The completed deposit inside a checkout status.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function paidDeposit(array $data): array
    {
        if (is_array($data['deposit'] ?? null)) {
            return $data['deposit'];
        }
        foreach (array_reverse((array)($data['depositsHistory'] ?? [])) as $row) {
            if (is_array($row) && strtoupper((string)($row['status'] ?? '')) === 'COMPLETED') {
                return $row;
            }
        }
        return [];
    }

    /**
     * Paid in full, in the right currency? Anything else is held for an
     * admin to look at rather than opening the course.
     *
     * @param array<string,mixed> $enrollment
     * @param array<string,mixed> $deposit
     * @return array{state:string,status:string}
     */
    private static function verifyAmount(array $enrollment, array $deposit, string $status): array
    {
        $paid = $deposit['amount'] ?? $deposit['depositedAmount'] ?? $deposit['requestedAmount'] ?? null;
        $currency = strtoupper((string)($deposit['currency'] ?? ''));
        if ($paid === null || !is_numeric($paid)) {
            return ['state' => 'pending', 'status' => 'NEEDS_REVIEW_NO_AMOUNT'];
        }
        if ($currency !== '' && $currency !== strtoupper((string)$enrollment['currency'])) {
            return ['state' => 'pending', 'status' => 'NEEDS_REVIEW_CURRENCY'];
        }
        if ((float)$paid + 0.01 < (float)$enrollment['amount']) {
            return ['state' => 'pending', 'status' => 'NEEDS_REVIEW_UNDERPAID'];
        }
        return ['state' => 'paid', 'status' => $status];
    }

    /** @param array<string|int,mixed> $res */
    private static function reason(array $res): string
    {
        $r = is_array($res['failureReason'] ?? null) ? $res['failureReason'] : [];
        $text = trim((string)($r['failureCode'] ?? '') . ' ' . (string)($r['failureMessage'] ?? ''));
        return $text !== '' ? $text : (string)($res['status'] ?? '');
    }

    /** The enrolment for a callback's checkoutId or depositId. */
    public static function findByCallback(array $body): ?array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        foreach ([self::CHECKOUT => 'checkoutId', self::DEPOSIT => 'depositId'] as $prefix => $key) {
            $id = (string)($data[$key] ?? $body[$key] ?? '');
            if ($id !== '' && wp_is_uuid($id)) {
                $row = Enrollments::findByGatewayRef('pawapay', $prefix . $id);
                if ($row !== null) {
                    return $row;
                }
            }
        }
        return null;
    }
}
