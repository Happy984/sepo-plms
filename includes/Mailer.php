<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Emails: the receipt when a payment is confirmed, the payment instructions
 * for a manual method, and a note to the site's admin address.
 *
 * A mail that fails to send never fails the enrolment behind it.
 */
final class Mailer
{
    /** @param array<string,mixed> $row */
    public static function receipt(array $row): void
    {
        if (!Settings::get('email_receipts')) {
            return;
        }
        /* translators: %s: course title */
        $subject = sprintf(__('Receipt: %s', 'sepo-lms'), (string)$row['title']);
        $intro = '<p>' . sprintf(
            /* translators: %s: learner name */
            esc_html__('Hello %s,', 'sepo-lms'),
            esc_html((string)$row['name'])
        ) . '</p><p>' . esc_html__('Your payment is confirmed and the course is open.', 'sepo-lms')
            . ' <a href="' . esc_url((string)get_permalink((int)$row['course_id'])) . '">' . esc_html__('Go to the course', 'sepo-lms') . '</a></p>';

        self::send((string)$row['email'], $subject, $intro . Receipt::html($row));

        if (Settings::get('email_admin')) {
            /* translators: 1: learner name, 2: course title */
            $s = sprintf(__('Payment received: %1$s — %2$s', 'sepo-lms'), (string)$row['name'], (string)$row['title']);
            self::send((string)Settings::get('admin_email'), $s, Receipt::html($row));
        }
    }

    /** @param array<string,mixed> $row */
    public static function pending(array $row, string $instructions, string $link = ''): void
    {
        if (Settings::get('email_receipts')) {
            /* translators: %s: course title */
            $subject = sprintf(__('How to pay for %s', 'sepo-lms'), (string)$row['title']);
            $body = '<p>' . sprintf(/* translators: %s: learner name */ esc_html__('Hello %s,', 'sepo-lms'), esc_html((string)$row['name'])) . '</p>'
                . '<p>' . esc_html__('Thanks for enrolling. Your place is reserved; the lessons open once your payment is confirmed.', 'sepo-lms') . '</p>'
                . ($instructions !== '' ? '<p style="padding:12px;border:1px solid #e5e7eb;border-radius:8px">' . nl2br(esc_html($instructions)) . '</p>' : '')
                . ($link !== '' ? '<p><a href="' . esc_url($link) . '">' . esc_html__('Pay now', 'sepo-lms') . '</a></p>' : '')
                . Receipt::html($row);
            self::send((string)$row['email'], $subject, $body);
        }
        if (Settings::get('email_admin')) {
            /* translators: 1: learner name, 2: course title */
            $s = sprintf(__('New enrolment awaiting payment: %1$s — %2$s', 'sepo-lms'), (string)$row['name'], (string)$row['title']);
            $body = Receipt::html($row) . '<p><a href="' . esc_url(admin_url('admin.php?page=sepo-lms-enrollments&status=pending')) . '">' . esc_html__('Review enrolments', 'sepo-lms') . '</a></p>';
            self::send((string)Settings::get('admin_email'), $s, $body);
        }
    }

    public static function send(string $to, string $subject, string $html): bool
    {
        if (!is_email($to)) {
            return false;
        }
        $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:12px;background:#f3f4f6;-webkit-text-size-adjust:100%">'
            . '<div style="max-width:620px;margin:0 auto;font:15px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#1f2937;word-break:break-word;overflow-wrap:anywhere">'
            . $html . '</div></body></html>';
        try {
            return (bool)wp_mail($to, wp_specialchars_decode($subject), $wrapped, ['Content-Type: text/html; charset=UTF-8']);
        } catch (\Throwable $e) {
            Payments::log('mail failed: ' . $e->getMessage());
            return false;
        }
    }
}
