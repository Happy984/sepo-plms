<?php

/**
 * Plugin Name:       Sepo LMS
 * Description:       Courses, lessons, quizzes, schools and teachers. Enrolment with free, manual, link, Stripe, PayPal, pawaPay mobile-money and WooCommerce payments, lesson progress, graded quizzes, reviews, receipts and an enrolments admin.
 * Version:           1.4.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Euneolink Solutions
 * Author URI:        https://sepodesk.com
 * Plugin URI:        https://edu.sepodesk.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sepo-lms
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Sepo LMS needs PHP 8.1 or newer. The plugin is installed but not running.', 'sepo-lms')
            . '</p></div>';
    });
    return;
}

define('SLMS_VERSION', '1.4.1');
define('SLMS_FILE', __FILE__);
define('SLMS_DIR', plugin_dir_path(__FILE__));
define('SLMS_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SepoLms\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = SLMS_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [\SepoLms\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\SepoLms\Activator::class, 'deactivate']);

add_action('plugins_loaded', [\SepoLms\Plugin::class, 'boot']);

// Works with WooCommerce's order tables (HPOS).
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
