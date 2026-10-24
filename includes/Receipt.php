<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * The receipt: one HTML block used in the email and on the printable page.
 */
final class Receipt
{
    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'maybeRender'], 1);
    }

    /** ?slms_receipt=REF shows a printable receipt to its owner or an admin. */
    public static function maybeRender(): void
    {
        if (!isset($_GET['slms_receipt'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $ref = sanitize_text_field(wp_unslash((string)$_GET['slms_receipt'])); // phpcs:ignore WordPress.Security.NonceVerification
        $row = Enrollments::findByReference($ref);
        $uid = get_current_user_id();

        if ($uid <= 0) {
            wp_safe_redirect(wp_login_url(Helpers::receiptUrl($ref)));
            exit;
        }
        if ($row === null || ((int)$row['user_id'] !== $uid && !current_user_can(Activator::CAP))) {
            wp_die(esc_html__('Receipt not found.', 'sepo-lms'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $title = sprintf(/* translators: %s: reference */ __('Receipt %s', 'sepo-lms'), $row['reference']);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . esc_html($title) . '</title>'
            . '<style>body{margin:0;background:#f3f4f6;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2937}'
            . '.slms-receipt-page{max-width:640px;margin:24px auto;padding:0 16px}'
            . '.slms-receipt-tools{display:flex;gap:8px;justify-content:flex-end;margin-bottom:12px}'
            . '.slms-receipt-tools a,.slms-receipt-tools button{font:inherit;padding:8px 14px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:inherit;text-decoration:none;cursor:pointer}'
            . '.slms-receipt-tools button{background:#1d475a;color:#fff;border-color:#1d475a}'
            . '@media print{body{background:#fff}.slms-receipt-tools{display:none}.slms-receipt-page{margin:0;max-width:none}}</style></head><body>'
            . '<div class="slms-receipt-page"><div class="slms-receipt-tools">'
            . '<a href="' . esc_url(Helpers::myCoursesUrl()) . '">' . esc_html__('My courses', 'sepo-lms') . '</a>'
            . '<button type="button" onclick="window.print()">' . esc_html__('Print', 'sepo-lms') . '</button></div>'
            . self::html($row) // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping below
            . '</div></body></html>';
        exit;
    }

    /**
     * Receipt markup with inline styles, so it survives email clients.
     *
     * @param array<string,mixed> $r
     */
    public static function html(array $r): string
    {
        $cur = (string)$r['currency'];
        $m = static fn(float $v): string => esc_html(Helpers::money($v, $cur));
        $paid = $r['status'] === 'paid';
        $statusLabel = self::statusLabel((string)$r['status']);
        $statusColor = $paid ? '#166534' : ($r['status'] === 'active' ? '#1d4ed8' : '#92400e');
        $statusBg = $paid ? '#dcfce7' : ($r['status'] === 'active' ? '#dbeafe' : '#fef3c7');

        // Details are stacked — label above value — so a long course name,
        // email address or reference wraps on a phone instead of pushing the
        // receipt wider than the screen. Amounts sit in a two-column table
        // with a fixed layout: labels wrap, amounts never split.
        $wrap = 'word-break:break-word;overflow-wrap:anywhere;';
        $detail = static fn(string $k, string $v, bool $strong = false): string =>
            '<div style="padding:7px 0;border-bottom:1px solid #f1f2f4">'
            . '<div style="font-size:12px;color:#6b7280;line-height:1.4">' . esc_html($k) . '</div>'
            . '<div style="font-size:14px;line-height:1.45;' . $wrap . ($strong ? 'font-weight:700;' : '') . '">' . $v . '</div></div>';
        $amount = static fn(string $k, string $v, bool $total = false): string =>
            '<tr><td style="padding:' . ($total ? '10px' : '6px') . ' 8px ' . ($total ? '0' : '6px') . ' 0;vertical-align:top;' . $wrap
            . ($total ? 'border-top:2px solid #1f2937;font-weight:700;' : 'color:#4b5563;') . '">' . esc_html($k) . '</td>'
            . '<td style="width:1%;padding:' . ($total ? '10px' : '6px') . ' 0 ' . ($total ? '0' : '6px') . ' 0;text-align:right;vertical-align:top;white-space:nowrap;'
            . ($total ? 'border-top:2px solid #1f2937;font-weight:700;font-size:16px;' : '') . '">' . $v . '</td></tr>';

        $business = (string)Settings::get('business_name');
        $taxNo = (string)Settings::get('business_tax_number');

        $h = '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:100%;box-sizing:border-box;' . $wrap . '">';
        $h .= '<div style="margin-bottom:4px">'
            . '<span style="float:right;margin:2px 0 6px 10px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;background:' . $statusBg . ';color:' . $statusColor . '">' . esc_html($statusLabel) . '</span>'
            . '<div style="font-size:18px;font-weight:700;line-height:1.3;' . $wrap . '">' . esc_html($business) . '</div>'
            . ($taxNo !== '' ? '<div style="font-size:13px;color:#6b7280">' . esc_html__('Tax number', 'sepo-lms') . ': ' . esc_html($taxNo) . '</div>' : '')
            . '<div style="clear:both"></div></div>';

        $h .= '<div style="margin:14px 0 2px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">'
            . esc_html($paid ? __('Payment receipt', 'sepo-lms') : __('Enrolment summary', 'sepo-lms')) . '</div>';

        $h .= $detail(__('Reference', 'sepo-lms'), esc_html((string)$r['reference']), true);
        $h .= $detail(__('Course', 'sepo-lms'), esc_html((string)$r['title']));
        $h .= $detail(__('Student', 'sepo-lms'), esc_html((string)$r['name']));
        $h .= $detail(__('Email', 'sepo-lms'), esc_html((string)$r['email']));
        if ((string)$r['level'] !== '') {
            $h .= $detail(__('Level', 'sepo-lms'), esc_html((string)$r['level']));
        }
        $h .= $detail(__('Enrolled', 'sepo-lms'), esc_html(Helpers::datetime((string)$r['created'])));
        if ($paid && !empty($r['paid_at'])) {
            $h .= $detail(__('Paid', 'sepo-lms'), esc_html(Helpers::datetime((string)$r['paid_at'])));
        }
        if ((string)$r['method'] !== '') {
            $h .= $detail(__('Payment method', 'sepo-lms'), esc_html((string)$r['method']));
        }

        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;margin-top:12px">';
        if ((float)$r['amount'] > 0) {
            $h .= $amount(__('Course price', 'sepo-lms'), $m((float)$r['base_price']));
            if ((float)$r['discount_amount'] > 0) {
                $label = ((string)$r['discount_label'] !== '' ? (string)$r['discount_label'] : __('Discount', 'sepo-lms')) . ' (' . Helpers::percent((float)$r['discount_rate']) . '%)';
                $h .= $amount($label, '−&nbsp;' . $m((float)$r['discount_amount']));
                $h .= $amount(__('Subtotal', 'sepo-lms'), $m((float)$r['unit_price']));
            }
            if ((float)$r['tax_amount'] > 0) {
                $label = trim((string)$r['tax_type'] . ' ' . Helpers::percent((float)$r['tax_rate']) . '%' . ((string)$r['tax_country'] !== '' ? ' (' . $r['tax_country'] . ')' : ''));
                $h .= $amount($label, $m((float)$r['tax_amount']));
            }
            $h .= $amount($paid ? __('Total paid', 'sepo-lms') : __('Total due', 'sepo-lms'), $m((float)$r['amount']), true);
        } else {
            $h .= $amount(__('Total', 'sepo-lms'), esc_html__('Free', 'sepo-lms'), true);
        }
        $h .= '</table>';

        $footer = (string)Settings::get('receipt_footer');
        if ($footer !== '') {
            $h .= '<p style="margin:18px 0 0;font-size:13px;color:#6b7280;text-align:center">' . nl2br(esc_html($footer)) . '</p>';
        }
        $h .= '</div>';
        return $h;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'paid'    => __('Paid', 'sepo-lms'),
            'active'  => __('Active', 'sepo-lms'),
            default   => __('Awaiting payment', 'sepo-lms'),
        };
    }
}
