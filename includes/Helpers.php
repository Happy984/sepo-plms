<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Small formatting helpers.
 */
final class Helpers
{
    public static function money(float $amount, string $currency): string
    {
        return $currency . ' ' . number_format_i18n($amount, 2);
    }

    /** Price as shown on a card or the side panel: "Free" when nothing is due. */
    public static function priceLabel(float $amount, string $currency): string
    {
        return $amount > 0 ? self::money($amount, $currency) : __('Free', 'sepo-lms');
    }

    public static function date(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts !== false ? date_i18n((string)get_option('date_format', 'j M Y'), $ts) : $raw;
    }

    /** A UTC MySQL datetime shown in site time. */
    public static function datetime(?string $gmt): string
    {
        if ($gmt === null || $gmt === '' || str_starts_with($gmt, '0000')) {
            return '';
        }
        return get_date_from_gmt($gmt, get_option('date_format', 'j M Y') . ' ' . get_option('time_format', 'H:i'));
    }

    public static function stars(float $avg): string
    {
        $full = (int)round($avg);
        $s = '';
        for ($i = 1; $i <= 5; $i++) {
            $s .= $i <= $full ? '★' : '☆';
        }
        return $s;
    }

    public static function percent(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    public static function loginUrl(string $back = ''): string
    {
        if ($back === '') {
            $back = is_singular() ? (string)get_permalink() : home_url(add_query_arg([]));
        }
        return wp_login_url($back);
    }

    public static function myCoursesUrl(): string
    {
        $id = (int)Settings::get('my_courses_page');
        $url = $id > 0 ? get_permalink($id) : false;
        return $url !== false ? (string)$url : home_url('/');
    }

    public static function receiptUrl(string $reference): string
    {
        return add_query_arg(['slms_receipt' => rawurlencode($reference)], home_url('/'));
    }

    /**
     * A phone number in international form without the plus: local numbers
     * starting with 0 get the site's country code in front.
     */
    public static function msisdn(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $cc = (string)Settings::get('phone_country_code');
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }
        if ($cc !== '' && str_starts_with($digits, $cc)) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            return $cc . substr($digits, 1);
        }
        if ($cc !== '' && strlen($digits) <= 9) {
            return $cc . $digits;
        }
        return $digits;
    }

    public static function letterGrade(float $avg): string
    {
        return match (true) {
            $avg >= 90 => 'A',
            $avg >= 80 => 'B',
            $avg >= 70 => 'C',
            $avg >= 60 => 'D',
            default    => 'F',
        };
    }

    /** Image URL for an attachment ID or a URL stored in meta. */
    public static function image(mixed $value, string $size = 'large'): string
    {
        if (is_numeric($value) && (int)$value > 0) {
            $url = wp_get_attachment_image_url((int)$value, $size);
            return $url !== false ? (string)$url : '';
        }
        return is_string($value) ? esc_url_raw($value) : '';
    }
}
