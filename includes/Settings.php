<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Plugin settings, stored as one option array.
 *
 * Payment methods are a list of rows:
 *   id, label, type (manual|link|stripe|paypal|pawapay|woocommerce), details, correspondent, enabled
 */
final class Settings
{
    public const OPTION = 'sepo_lms_settings';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'courses_base'        => 'courses',
            'lessons_base'        => 'lessons',
            'quizzes_base'        => 'quizzes',
            'schools_base'        => 'schools',
            'teachers_base'       => 'teachers',
            'currency'            => 'ZMW',
            'per_page'            => 12,
            'accent'              => '#1d475a',
            'business_name'       => get_bloginfo('name'),
            'business_tax_number' => '',
            'receipt_footer'      => __('Thank you for enrolling.', 'sepo-lms'),
            'admin_email'         => get_option('admin_email'),
            'email_receipts'      => 1,
            'email_admin'         => 1,
            'collect_national_id' => 1,
            'collect_gender'      => 1,
            'collect_location'    => 1,
            'collect_dob'         => 1,
            'my_courses_page'     => 0,
            'methods'             => [
                [
                    'id'            => 'bank',
                    'label'         => __('Bank transfer', 'sepo-lms'),
                    'type'          => 'manual',
                    'details'       => __('Pay into our account and send the slip to us. Your enrolment opens once payment is confirmed.', 'sepo-lms'),
                    'correspondent' => '',
                    'enabled'       => 1,
                ],
            ],
            'stripe_secret'       => '',
            'stripe_webhook'      => '',
            'paypal_client'       => '',
            'paypal_secret'       => '',
            'paypal_sandbox'      => 1,
            'pawapay_token'       => '',
            'pawapay_sandbox'     => 1,
            'pawapay_country'     => 'ZMB',
            'phone_country_code'  => '260',
            'pawapay_base_live'   => 'https://api.pawapay.io',
            'pawapay_base_sandbox'=> 'https://api.sandbox.pawapay.io',
            'delete_on_uninstall' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, []);
            self::$cache = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        }
        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? (self::defaults()[$key] ?? null);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function base(string $which): string
    {
        $v = sanitize_title((string)self::get($which . '_base'));
        return $v !== '' ? $v : (string)self::defaults()[$which . '_base'];
    }

    /**
     * Enabled payment methods, keyed by id.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function methods(): array
    {
        $out = [];
        foreach ((array)self::get('methods') as $m) {
            if (!is_array($m) || empty($m['enabled']) || ($m['id'] ?? '') === '') {
                continue;
            }
            $out[(string)$m['id']] = $m;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function sanitize(array $in): array
    {
        $d = self::defaults();
        $old = self::all();
        $out = $old;

        foreach (['courses', 'lessons', 'quizzes', 'schools', 'teachers'] as $b) {
            $k = $b . '_base';
            if (isset($in[$k])) {
                $out[$k] = sanitize_title((string)$in[$k]) ?: $d[$k];
            }
        }
        foreach (['business_name', 'business_tax_number', 'stripe_secret', 'stripe_webhook',
                     'paypal_client', 'paypal_secret', 'pawapay_token'] as $k) {
            if (isset($in[$k])) {
                $out[$k] = sanitize_text_field((string)$in[$k]);
            }
        }
        foreach (['pawapay_base_live', 'pawapay_base_sandbox'] as $k) {
            if (isset($in[$k])) {
                $out[$k] = esc_url_raw(untrailingslashit((string)$in[$k]), ['https']) ?: $d[$k];
            }
        }
        if (isset($in['currency'])) {
            $c = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$in['currency']) ?? '');
            $out['currency'] = strlen($c) === 3 ? $c : $d['currency'];
        }
        if (isset($in['pawapay_country'])) {
            $c = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$in['pawapay_country']) ?? '');
            $out['pawapay_country'] = strlen($c) === 3 ? $c : $d['pawapay_country'];
        }
        if (isset($in['phone_country_code'])) {
            $out['phone_country_code'] = preg_replace('/\D/', '', (string)$in['phone_country_code']) ?: $d['phone_country_code'];
        }
        if (isset($in['receipt_footer'])) {
            $out['receipt_footer'] = sanitize_textarea_field((string)$in['receipt_footer']);
        }
        if (isset($in['admin_email'])) {
            $out['admin_email'] = sanitize_email((string)$in['admin_email']);
        }
        if (isset($in['accent'])) {
            $out['accent'] = sanitize_hex_color((string)$in['accent']) ?: $d['accent'];
        }
        if (isset($in['per_page'])) {
            $out['per_page'] = max(1, min(100, (int)$in['per_page']));
        }
        if (isset($in['my_courses_page'])) {
            $out['my_courses_page'] = absint($in['my_courses_page']);
        }
        foreach (['email_receipts', 'email_admin', 'collect_national_id', 'collect_gender', 'collect_location',
                     'collect_dob', 'paypal_sandbox', 'pawapay_sandbox', 'delete_on_uninstall'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = empty($in[$k]) ? 0 : 1;
            }
        }

        if (isset($in['methods']) && is_array($in['methods'])) {
            $methods = [];
            $seen = [];
            foreach ($in['methods'] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $id = sanitize_key((string)($m['id'] ?? ''));
                $label = sanitize_text_field((string)($m['label'] ?? ''));
                if ($id === '' || $label === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $type = (string)($m['type'] ?? 'manual');
                if (!in_array($type, ['manual', 'link', 'stripe', 'paypal', 'pawapay', 'woocommerce'], true)) {
                    $type = 'manual';
                }
                $methods[] = [
                    'id'            => $id,
                    'label'         => $label,
                    'type'          => $type,
                    'details'       => $type === 'link'
                        ? esc_url_raw((string)($m['details'] ?? ''), ['http', 'https'])
                        : sanitize_textarea_field((string)($m['details'] ?? '')),
                    'correspondent' => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($m['correspondent'] ?? '')) ?? ''),
                    'enabled'       => empty($m['enabled']) ? 0 : 1,
                ];
            }
            $out['methods'] = $methods;
        }

        return $out;
    }
}
