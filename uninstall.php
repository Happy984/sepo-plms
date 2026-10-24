<?php
/**
 * Runs when Sepo LMS is deleted from the Plugins screen.
 *
 * Courses, lessons, quizzes, schools and teachers are posts and are never
 * removed. Enrolments, progress, quiz results, reviews and settings are
 * removed only if "Delete data on uninstall" was ticked in LMS → Settings.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('sepo_lms_payment_sweep');

foreach (['administrator', 'editor'] as $roleName) {
    $role = get_role($roleName);
    if ($role) {
        $role->remove_cap('manage_sepo_lms');
    }
}

$settings = get_option('sepo_lms_settings', []);

if (is_array($settings) && !empty($settings['delete_on_uninstall'])) {
    global $wpdb;
    foreach (['slms_enrollments', 'slms_progress', 'slms_quiz_results', 'slms_reviews'] as $table) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB
    }
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_slms\\_qstart\\_%'"); // phpcs:ignore WordPress.DB
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_slms\\_%' OR option_name LIKE '\\_transient\\_timeout\\_slms\\_%'"); // phpcs:ignore WordPress.DB
    delete_option('sepo_lms_settings');
    delete_option('sepo_lms_db');
    delete_option('sepo_lms_flush');
}
