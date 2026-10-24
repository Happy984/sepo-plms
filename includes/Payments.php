<?php

declare(strict_types=1);

namespace SepoLms;

use SepoLms\Gateways\Gateway;
use SepoLms\Gateways\HttpError;
use SepoLms\Gateways\PawaPay;
use SepoLms\Gateways\PayPal;
use SepoLms\Gateways\Stripe;
use SepoLms\Gateways\WooCommerce;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Enrolling, taking payment and settling it.
 *
 * Method types:
 *   manual   bank transfer, cash … the enrolment waits until an admin marks it paid
 *   link     a payment link shown to the learner; also confirmed by an admin
 *   stripe   Stripe Checkout
 *   paypal   PayPal Checkout
 *   pawapay  pawaPay mobile money
 *   woocommerce  a WooCommerce order, paid with any of the shop's gateways
 *
 * A gateway payment is confirmed only by asking the gateway: on return from
 * the checkout, when a webhook arrives, when the course page polls, and in
 * an hourly sweep. Each of these calls check(), and Enrollments::settle()
 * makes sure only the first one to see "paid" acts on it.
 */
final class Payments
{
    public const CRON_HOOK = 'sepo_lms_payment_sweep';
    public const GATEWAYS = ['stripe', 'paypal', 'pawapay', 'woocommerce'];

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'sweep']);
        WooCommerce::init();
    }

    public static function gateway(string $type): ?Gateway
    {
        return match ($type) {
            'stripe'  => new Stripe(),
            'paypal'  => new PayPal(),
            'pawapay' => new PawaPay(),
            'woocommerce' => new WooCommerce(),
            default   => null,
        };
    }

    /**
     * Enabled methods whose gateway (if any) has credentials.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function available(): array
    {
        $out = [];
        foreach (Settings::methods() as $id => $m) {
            $type = (string)($m['type'] ?? 'manual');
            if (in_array($type, self::GATEWAYS, true)) {
                $g = self::gateway($type);
                if ($g === null || !$g->ready()) {
                    continue;
                }
            }
            $out[$id] = $m;
        }
        return $out;
    }

    public static function returnUrl(string $reference, string $gateway): string
    {
        return add_query_arg(['ref' => rawurlencode($reference), 'gw' => $gateway], rest_url(Rest::NS . '/pay/return'));
    }

    /** Whether a method needs a phone number from the learner. */
    public static function needsPhone(array $method): bool
    {
        return ($method['type'] ?? '') === 'pawapay' && (string)($method['correspondent'] ?? '') !== '';
    }

    /**
     * Enrol the signed-in learner.
     *
     * @param array<string,mixed> $form name, email, national_id, gender, location, dob, method, phone
     * @return array<string,mixed> what the course page needs to show next
     */
    public static function enroll(int $courseId, int $userId, array $form): array
    {
        $course = Course::get($courseId);
        if ($course === null || get_post_status($courseId) !== 'publish') {
            throw new \DomainException(__('Course not found.', 'sepo-lms'), 404);
        }

        $name = trim(sanitize_text_field((string)($form['name'] ?? '')));
        $email = sanitize_email((string)($form['email'] ?? ''));
        if ($name === '' || !is_email($email)) {
            throw new \DomainException(__('Your name and a valid email address are required.', 'sepo-lms'), 422);
        }

        $existing = Enrollments::forCourseUser($courseId, $userId);
        $retry = false;
        if ($existing !== null) {
            // Someone who left a gateway checkout can start it again; anyone
            // else is already enrolled.
            if ($existing['status'] === 'pending' && $existing['gateway'] !== '' && !$course['free']) {
                // The first attempt may have gone through after all.
                if (self::verify($existing) === 'paid') {
                    throw new \DomainException(__('Your payment has gone through — you are enrolled. Reload the page.', 'sepo-lms'), 409);
                }
                $existing = Enrollments::find((int)$existing['id']) ?? $existing;
                if ($existing['gateway'] === 'pawapay' && PawaPay::inFlight($existing)) {
                    throw new \DomainException(__('A payment request is still waiting on your phone. Approve it, or try again in a few minutes.', 'sepo-lms'), 409);
                }
                // Close the abandoned checkout so it cannot be paid as well.
                $old = self::gateway((string)$existing['gateway']);
                if ($old !== null && $existing['gateway_ref'] !== '' && method_exists($old, 'abandon')) {
                    $old->abandon($existing);
                }
                $retry = true;
            } else {
                throw new \DomainException(__('You are already enrolled in this course.', 'sepo-lms'), 409);
            }
        }

        $phone = trim(sanitize_text_field((string)($form['phone'] ?? '')));
        $method = null;
        $methodId = '';
        $status = 'active';
        $type = 'free';

        if (!$course['free']) {
            $methods = Course::methods($course);
            $methodId = sanitize_key((string)($form['method'] ?? ''));
            if ($methods === []) {
                throw new \DomainException(__('No payment method is set up for this course.', 'sepo-lms'), 422);
            }
            if ($methodId === '' || !isset($methods[$methodId])) {
                throw new \DomainException(__('Choose a valid payment method.', 'sepo-lms'), 422);
            }
            $method = $methods[$methodId];
            $type = (string)$method['type'];
            if (self::needsPhone($method) && Helpers::msisdn($phone) === '') {
                throw new \DomainException(__('A phone number is required for mobile money payments.', 'sepo-lms'), 422);
            }
            if ($type === 'paypal' && !in_array($course['currency'], PayPal::CURRENCIES, true)) {
                /* translators: %s: currency code */
                throw new \DomainException(sprintf(__('PayPal cannot take payments in %s. Choose another payment method.', 'sepo-lms'), $course['currency']), 422);
            }
            $status = 'pending';
        }

        $data = [
            'course_id'       => $courseId,
            'user_id'         => $userId,
            'title'           => $course['title'],
            'name'            => $name,
            'email'           => $email,
            'national_id'     => $form['national_id'] ?? '',
            'gender'          => $form['gender'] ?? '',
            'location'        => $form['location'] ?? '',
            'dob'             => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($form['dob'] ?? '')) ? $form['dob'] : '',
            'phone'           => $phone,
            'level'           => $course['level'],
            'status'          => $status,
            'method_id'       => $methodId,
            'method'          => $method['label'] ?? '',
            'method_type'     => $type,
            'currency'        => $course['currency'],
            'base_price'      => $course['price'],
            'discount_rate'   => $course['discount_rate'],
            'discount_label'  => $course['discount_rate'] > 0 ? $course['discount_label'] : '',
            'discount_amount' => $course['discount'],
            'unit_price'      => $course['unit'],
            'tax_rate'        => $course['tax_rate'],
            'tax_type'        => $course['tax_rate'] > 0 ? $course['tax_type'] : '',
            'tax_country'     => $course['tax_rate'] > 0 ? $course['tax_country'] : '',
            'tax_amount'      => $course['tax'],
            'quantity'        => 1,
            'amount'          => $course['free'] ? 0 : $course['total'],
            'gateway'         => in_array($type, self::GATEWAYS, true) ? $type : '',
        ];

        if ($retry && $existing !== null) {
            Enrollments::refresh((int)$existing['id'], $data);
            $row = Enrollments::find((int)$existing['id']) ?? $existing;
        } else {
            try {
                $row = Enrollments::create($data);
            } catch (\RuntimeException $e) {
                throw new \DomainException($e->getMessage(), 409);
            }
            do_action('sepo_lms_enrolled', $row);
        }

        $out = [
            'reference'    => $row['reference'],
            'status'       => $row['status'],
            'paid'         => false,
            'free'         => $course['free'],
            'checkout_url' => '',
            'poll'         => false,
            'instructions' => '',
            'receipt_url'  => '',
            'total'        => Helpers::priceLabel((float)$row['amount'], (string)$row['currency']),
        ];

        if ($course['free']) {
            $out['instructions'] = __('You are enrolled. The course is open.', 'sepo-lms');
            return $out;
        }

        if ($type === 'manual' || $type === 'link') {
            $out['instructions'] = (string)($method['details'] ?? '');
            $out['link'] = $type === 'link' ? (string)$method['details'] : '';
            if ($type === 'link') {
                $out['instructions'] = __('Complete payment using the link below. Your enrolment opens once payment is confirmed.', 'sepo-lms');
            }
            Mailer::pending($row, $out['instructions'], $out['link'] ?? '');
            return $out;
        }

        $gateway = self::gateway($type);
        if ($gateway === null) {
            throw new \DomainException(__('That payment method is unavailable.', 'sepo-lms'), 422);
        }
        try {
            $started = $gateway->start($row, (array)$method, $phone);
        } catch (\Throwable $e) {
            $definite = !$e instanceof HttpError || $e->isDefinitiveRejection();
            if ($definite) {
                // Nothing was created at the payment service, so nothing can be paid.
                if (!$retry) {
                    Enrollments::delete((int)$row['id']);
                } else {
                    Enrollments::setGateway((int)$row['id'], '', 'REJECTED');
                }
                /* translators: %s: error from the payment service */
                throw new \DomainException(sprintf(__('Could not start the payment: %s', 'sepo-lms'), $e->getMessage()), 422);
            }
            // The request may have reached the payment service. Keep the
            // enrolment and its reference so the hourly check can settle it.
            self::log('payment start outcome unknown for ' . $row['reference'] . ': ' . $e->getMessage());
            $fresh = Enrollments::find((int)$row['id']) ?? $row;
            if ($fresh['gateway'] === 'pawapay' && str_starts_with((string)$fresh['gateway_ref'], PawaPay::DEPOSIT)) {
                $out['poll'] = true;
                $out['instructions'] = __('We could not confirm the payment request. If a prompt appears on your phone, approve it — this page will update. If nothing arrives, try again in a few minutes.', 'sepo-lms');
                return $out;
            }
            throw new \DomainException(__('The payment service did not answer. Please try again in a minute.', 'sepo-lms'), 503);
        }
        Enrollments::setGateway((int)$row['id'], $started['ref'], $started['status']);

        $out['checkout_url'] = $started['url'];
        $out['poll'] = $started['poll'];
        $out['instructions'] = $started['message'] !== ''
            ? $started['message']
            : __('Taking you to the secure checkout…', 'sepo-lms');
        return $out;
    }

    /**
     * Ask the gateway about a pending enrolment and settle it if paid.
     *
     * @param array<string,mixed> $row
     * @return string paid, pending or failed
     */
    public static function verify(array $row): string
    {
        if ($row['status'] === 'paid') {
            return 'paid';
        }
        if ($row['status'] !== 'pending' || $row['gateway'] === '' || $row['gateway_ref'] === '') {
            return 'pending';
        }
        $gateway = self::gateway((string)$row['gateway']);
        if ($gateway === null || !$gateway->ready()) {
            return 'pending';
        }
        try {
            $res = $gateway->check($row);
        } catch (\Throwable $e) {
            self::log('check failed for ' . $row['reference'] . ': ' . $e->getMessage());
            return 'pending';
        }
        if ($res['state'] === 'paid') {
            Enrollments::settle((int)$row['id'], '', $res['status']);
            return 'paid';
        }
        if ($res['status'] !== '' && $res['status'] !== $row['gateway_status']) {
            Enrollments::setGatewayStatus((int)$row['id'], $res['status']);
        }
        return $res['state'];
    }

    /** Hourly: catch payments whose return and webhook were both missed. */
    public static function sweep(): void
    {
        foreach (Enrollments::pendingGateway() as $row) {
            self::verify($row);
        }
    }

    // --------------------------------------------------------- REST handlers

    /**
     * The learner comes back from a checkout. Verify with the gateway, then
     * send them to the course page with the outcome.
     */
    public static function handleReturn(WP_REST_Request $req): WP_REST_Response
    {
        $row = Enrollments::findByReference(sanitize_text_field((string)$req->get_param('ref')));
        $home = home_url('/');
        if ($row === null) {
            return self::redirect($home);
        }
        $courseUrl = (string)(get_permalink((int)$row['course_id']) ?: $home);

        // PayPal hands back the order id as "token"; make sure it is ours.
        $token = sanitize_text_field((string)$req->get_param('token'));
        if ($row['gateway'] === 'paypal' && $token !== '' && $token !== $row['gateway_ref']) {
            return self::redirect(add_query_arg('slms_payment', 'pending', $courseUrl));
        }

        $state = self::verify($row);
        if ($state !== 'paid' && $req->get_param('cancelled')) {
            $state = 'cancelled';
        }
        return self::redirect(add_query_arg('slms_payment', $state, $courseUrl));
    }

    private static function redirect(string $url): WP_REST_Response
    {
        $res = new WP_REST_Response(null, 302);
        $res->header('Location', esc_url_raw($url));
        return $res;
    }

    public static function stripeWebhook(WP_REST_Request $req): WP_REST_Response
    {
        $payload = $req->get_body();
        if (!Stripe::verifySignature($payload, (string)$req->get_header('stripe_signature'), (string)Settings::get('stripe_webhook'))) {
            return new WP_REST_Response(['received' => false], 400);
        }
        $event = json_decode($payload, true);
        $type = (string)($event['type'] ?? '');
        $session = $event['data']['object'] ?? [];
        if (str_starts_with($type, 'checkout.session.') && is_array($session)) {
            $row = Enrollments::findByGatewayRef('stripe', (string)($session['id'] ?? ''));
            if ($row !== null) {
                self::verify($row);
            }
        }
        return new WP_REST_Response(['received' => true], 200);
    }

    /**
     * pawaPay callback. Its body only tells us which deposit to look up;
     * the status comes from pawaPay's own API.
     */
    public static function pawapayWebhook(WP_REST_Request $req): WP_REST_Response
    {
        $body = json_decode($req->get_body(), true);
        $row = is_array($body) ? PawaPay::findByCallback($body) : null;
        if ($row !== null) {
            self::verify($row);
        }
        return new WP_REST_Response(['received' => true], 200);
    }

    public static function paypalWebhook(WP_REST_Request $req): WP_REST_Response
    {
        $body = json_decode($req->get_body(), true);
        $resource = is_array($body) ? ($body['resource'] ?? []) : [];
        $orderId = (string)($resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? '');
        if ($orderId !== '') {
            $row = Enrollments::findByGatewayRef('paypal', sanitize_text_field($orderId));
            if ($row !== null) {
                self::verify($row);
            }
        }
        return new WP_REST_Response(['received' => true], 200);
    }

    public static function log(string $msg): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[sepo-lms] ' . $msg); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }
    }
}
