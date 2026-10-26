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
            'assignments_base'    => 'assignments',
            'schools_base'        => 'schools',
            'teachers_base'       => 'teachers',
            'currency'            => 'ZMW',
            'per_page'            => 12,
            'accent'              => '#1d475a',
            'accent_dark'         => '',
            'color_mode'          => 'auto',
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
            'feature_assignments' => 1,
            'feature_proctoring'  => 1,
            'feature_certificates'=> 1,
            'feature_forums'      => 1,
            'feature_study'       => 1,
            'feature_attendance'  => 1,
            'feature_chat'        => 1,
            'upload_max_mb'       => 20,
            'recording_retention' => 90,
            'chat_retention'      => 0,
            'chat_learner_dm'     => 0,
            'chat_poll'           => 5,
            'certificate_signatory' => '',
            'certificate_signatory_title' => '',
            'certificate_logo'    => 0,
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

    /** Is a classroom feature switched on site-wide? (assignments, proctoring, certificates, forums, study, attendance, chat) */
    public static function feature(string $name): bool
    {
        return !empty(self::get('feature_' . $name));
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

        foreach (['courses', 'lessons', 'quizzes', 'assignments', 'schools', 'teachers'] as $b) {
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
        if (isset($in['accent_dark'])) {
            $out['accent_dark'] = (string)(sanitize_hex_color((string)$in['accent_dark']) ?? '');
        }
        if (isset($in['color_mode'])) {
            $out['color_mode'] = in_array($in['color_mode'], ['auto', 'light', 'dark'], true) ? (string)$in['color_mode'] : 'auto';
        }
        if (isset($in['per_page'])) {
            $out['per_page'] = max(1, min(100, (int)$in['per_page']));
        }
        if (isset($in['my_courses_page'])) {
            $out['my_courses_page'] = absint($in['my_courses_page']);
        }
        if (isset($in['upload_max_mb'])) {
            $out['upload_max_mb'] = max(1, min(2048, (int)$in['upload_max_mb']));
        }
        if (isset($in['recording_retention'])) {
            $out['recording_retention'] = max(0, min(3650, (int)$in['recording_retention']));
        }
        if (isset($in['chat_retention'])) {
            $out['chat_retention'] = max(0, min(3650, (int)$in['chat_retention']));
        }
        if (isset($in['chat_poll'])) {
            $out['chat_poll'] = max(2, min(60, (int)$in['chat_poll']));
        }
        foreach (['certificate_signatory', 'certificate_signatory_title'] as $k) {
            if (isset($in[$k])) {
                $out[$k] = sanitize_text_field((string)$in[$k]);
            }
        }
        if (isset($in['certificate_logo'])) {
            $logo = absint($in['certificate_logo']);
            $out['certificate_logo'] = $logo > 0 && wp_attachment_is_image($logo) ? $logo : 0;
        }
        foreach (['email_receipts', 'email_admin', 'collect_national_id', 'collect_gender', 'collect_location',
                     'collect_dob', 'paypal_sandbox', 'pawapay_sandbox', 'delete_on_uninstall',
                     'feature_assignments', 'feature_proctoring', 'feature_certificates', 'feature_forums',
                     'feature_study', 'feature_attendance', 'feature_chat', 'chat_learner_dm'] as $k) {
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

    /**
     * Text colour that reads on a background colour: white or near-black,
     * whichever has the higher WCAG contrast against it.
     */
    public static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return '#ffffff';
        }
        $lin = static function (int $c): float {
            $v = $c / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int)hexdec(substr($hex, 0, 2)))
            + 0.7152 * $lin((int)hexdec(substr($hex, 2, 2)))
            + 0.0722 * $lin((int)hexdec(substr($hex, 4, 2)));
        // #0b1215 has a relative luminance of about 0.006.
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.056) ? '#ffffff' : '#0b1215';
    }

    /**
     * The accent for dark pages when none is set: the same hue, raised to a
     * lightness that reads on a dark background.
     */
    public static function lighten(string $hex, float $minLightness = 0.64): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return '#8fc1d6';
        }
        [$r, $g, $b] = array_map(static fn(string $c): float => hexdec($c) / 255, str_split($hex, 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $h = 0.0;
        $sat = 0.0;
        if ($max !== $min) {
            $d = $max - $min;
            $sat = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            $h = match ($max) {
                $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
                $g => ($b - $r) / $d + 2,
                default => ($r - $g) / $d + 4,
            } / 6;
        }
        $l = max($l, $minLightness);
        $sat = min(0.75, max($sat, $sat > 0 ? 0.45 : 0));
        $hue = static function (float $p, float $q, float $t): float {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };
        $q = $l < 0.5 ? $l * (1 + $sat) : $l + $sat - $l * $sat;
        $p = 2 * $l - $q;
        $rgb = [$hue($p, $q, $h + 1 / 3), $hue($p, $q, $h), $hue($p, $q, $h - 1 / 3)];
        return '#' . implode('', array_map(static fn(float $c): string => str_pad(dechex((int)round($c * 255)), 2, '0', STR_PAD_LEFT), $rgb));
    }

    /** The CSS custom properties printed with the stylesheet. */
    public static function cssVars(): string
    {
        $accent = (string)self::get('accent');
        $dark = (string)self::get('accent_dark');
        $auto = self::lighten($accent);
        $vars = '--slms-accent:' . $accent . ';--slms-on-accent-light:' . self::textOn($accent) . ';'
            . '--slms-accent-auto-dark:' . $auto . ';--slms-on-accent-auto-dark:' . self::textOn($auto) . ';';
        if ($dark !== '') {
            $vars .= '--slms-accent-dark:' . $dark . ';--slms-on-accent-dark:' . self::textOn($dark) . ';';
        }
        return ':root{' . $vars . '}';
    }
}
