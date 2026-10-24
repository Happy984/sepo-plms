<?php

declare(strict_types=1);

namespace SepoLms\Gateways;

use SepoLms\Enrollments;
use SepoLms\Payments;

/**
 * WooCommerce as a payment method.
 *
 * Enrolling creates a WooCommerce order for the course — one line for the
 * course at its discounted price and, when the course charges tax, one fee
 * line for the tax — owned by the learner. The learner is sent to the
 * order's "pay for order" page and pays with any gateway the shop has.
 *
 * The enrolment opens when WooCommerce marks the order paid (processing or
 * completed). A refunded or cancelled order puts the enrolment back to
 * "awaiting payment", which closes the lessons again.
 *
 * Totals are set by this plugin, not recalculated by WooCommerce's tax
 * settings, so the order charges exactly what the course page showed.
 */
final class WooCommerce implements Gateway
{
    public const META = '_slms_enrollment_id';

    public static function active(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_create_order');
    }

    public function ready(): bool
    {
        return self::active();
    }

    public static function init(): void
    {
        add_action('woocommerce_order_status_changed', [self::class, 'statusChanged'], 10, 4);
        add_action('woocommerce_payment_complete', [self::class, 'paymentComplete']);
        add_action('woocommerce_thankyou', [self::class, 'thankYou'], 5);
        add_action('woocommerce_view_order', [self::class, 'thankYou'], 5);
    }

    public function start(array $enrollment, array $method, string $phone): array
    {
        if (!self::active()) {
            throw new \RuntimeException(__('WooCommerce is not active.', 'sepo-lms'));
        }
        $enrollmentId = (int)$enrollment['id'];

        // A learner trying again: cancel the order they abandoned.
        foreach (self::ordersFor($enrollmentId) as $old) {
            if ($old->needs_payment()) {
                $old->update_status('cancelled', __('Replaced by a new payment attempt for the same enrolment.', 'sepo-lms'));
            }
        }

        $order = wc_create_order([
            'customer_id' => (int)$enrollment['user_id'],
            'created_via' => 'sepo-lms',
        ]);
        if (is_wp_error($order)) {
            throw new \RuntimeException($order->get_error_message());
        }

        $order->set_currency((string)$enrollment['currency']);
        [$first, $last] = array_pad(explode(' ', trim((string)$enrollment['name']), 2), 2, '');
        $order->set_billing_first_name($first);
        $order->set_billing_last_name($last);
        $order->set_billing_email((string)$enrollment['email']);
        if ((string)$enrollment['phone'] !== '') {
            $order->set_billing_phone((string)$enrollment['phone']);
        }

        $unit = round((float)$enrollment['unit_price'], 2);
        $item = new \WC_Order_Item_Product();
        $item->set_name((string)$enrollment['title']);
        $item->set_quantity(1);
        $item->set_subtotal((string)round((float)$enrollment['base_price'], 2));
        $item->set_total((string)$unit);
        $item->add_meta_data(__('Reference', 'sepo-lms'), (string)$enrollment['reference'], true);
        $order->add_item($item);

        $tax = round((float)$enrollment['tax_amount'], 2);
        if ($tax > 0) {
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name(trim((string)$enrollment['tax_type'] . ' ' . rtrim(rtrim(number_format((float)$enrollment['tax_rate'], 2, '.', ''), '0'), '.') . '%'));
            $fee->set_tax_status('none');
            $fee->set_total((string)$tax);
            $order->add_item($fee);
        }

        $order->update_meta_data(self::META, $enrollmentId);
        $order->update_meta_data('_slms_reference', (string)$enrollment['reference']);
        $order->update_meta_data('_slms_course_id', (int)$enrollment['course_id']);
        $order->calculate_totals(false);

        // Guard against anything that changed the total on the way.
        if (abs((float)$order->get_total() - (float)$enrollment['amount']) >= 0.01) {
            $order->set_total((string)round((float)$enrollment['amount'], 2));
        }
        $order->set_status('pending');
        $order->save();
        $order->add_order_note(sprintf(
            /* translators: %s: enrolment reference */
            __('Created by Sepo LMS for enrolment %s.', 'sepo-lms'),
            (string)$enrollment['reference']
        ));

        return [
            'url'     => $order->get_checkout_payment_url(),
            'ref'     => (string)$order->get_id(),
            'status'  => 'pending',
            'poll'    => false,
            'message' => '',
        ];
    }

    public function check(array $enrollment): array
    {
        $order = self::order($enrollment);
        if ($order === null) {
            return ['state' => 'pending', 'status' => 'NO_ORDER'];
        }
        $status = $order->get_status();
        if (self::paidFor($order, $enrollment)) {
            return ['state' => 'paid', 'status' => $status];
        }
        if (in_array($status, ['cancelled', 'failed', 'refunded'], true)) {
            return ['state' => 'failed', 'status' => $status];
        }
        return ['state' => 'pending', 'status' => $status];
    }

    /** @param array<string,mixed> $enrollment */
    private static function order(array $enrollment): ?\WC_Order
    {
        if (!self::active() || (string)$enrollment['gateway_ref'] === '') {
            return null;
        }
        $order = wc_get_order((int)$enrollment['gateway_ref']);
        if (!$order instanceof \WC_Order || (int)$order->get_meta(self::META) !== (int)$enrollment['id']) {
            return null;
        }
        return $order;
    }

    /**
     * Paid, for this enrolment, for the full amount, in the right currency.
     *
     * @param array<string,mixed> $enrollment
     */
    private static function paidFor(\WC_Order $order, array $enrollment): bool
    {
        return $order->is_paid()
            && (int)$order->get_meta(self::META) === (int)$enrollment['id']
            && strtoupper($order->get_currency()) === strtoupper((string)$enrollment['currency'])
            && (float)$order->get_total() + 0.009 >= (float)$enrollment['amount']
            && (float)$order->get_total_refunded() <= 0;
    }

    /** @return list<\WC_Order> */
    private static function ordersFor(int $enrollmentId): array
    {
        $orders = wc_get_orders([
            'limit'      => 20,
            'meta_query' => [['key' => self::META, 'value' => (string)$enrollmentId]], // phpcs:ignore WordPress.DB.SlowDBQuery
        ]);
        return array_values(array_filter((array)$orders, static fn($o) => $o instanceof \WC_Order));
    }

    /** @return array<string,mixed>|null */
    private static function enrollmentFor(\WC_Order $order): ?array
    {
        $id = (int)$order->get_meta(self::META);
        if ($id <= 0) {
            return null;
        }
        $row = Enrollments::find($id);
        // Only the order the enrolment is currently waiting on counts.
        if ($row === null || $row['gateway'] !== 'woocommerce' || (int)$row['gateway_ref'] !== $order->get_id()) {
            return null;
        }
        return $row;
    }

    public static function statusChanged(int $orderId, string $from, string $to, $order = null): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $row = self::enrollmentFor($order);
        if ($row === null) {
            return;
        }
        if (in_array($to, ['processing', 'completed'], true)) {
            Payments::verify($row);
            return;
        }
        if (in_array($to, ['refunded', 'cancelled'], true) && $row['status'] === 'paid') {
            Enrollments::setStatus((int)$row['id'], 'pending');
            Enrollments::setGatewayStatus((int)$row['id'], $to);
            /* translators: %s: order status, e.g. refunded */
            $order->add_order_note(sprintf(__('Sepo LMS: course access closed because the order was %s.', 'sepo-lms'), $to));
            return;
        }
        Enrollments::setGatewayStatus((int)$row['id'], $to);
    }

    public static function paymentComplete(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && ($row = self::enrollmentFor($order)) !== null) {
            Payments::verify($row);
        }
    }

    /** A "Go to your course" box on the order-received and view-order pages. */
    public static function thankYou(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $courseId = (int)$order->get_meta('_slms_course_id');
        if ($courseId <= 0) {
            return;
        }
        $url = get_permalink($courseId);
        if (!$url) {
            return;
        }
        $paid = $order->is_paid();
        echo '<div class="woocommerce-message slms-woo-course" role="status">'
            . esc_html($paid ? __('Payment received — your course is open.', 'sepo-lms') : __('Your enrolment opens once this order is paid.', 'sepo-lms'))
            . ' <a class="button" href="' . esc_url(add_query_arg('slms_payment', $paid ? 'paid' : 'pending', (string)$url)) . '">'
            . esc_html__('Go to the course', 'sepo-lms') . '</a></div>';
    }
}
