<?php
/**
 * Runs when Sepo LMS is deleted from the Plugins screen.
 *
 * Courses, lessons, quizzes, assignments, schools and teachers are posts and
 * are never removed. Enrolments, progress, quiz results, reviews, classroom
 * data (hand-ins and their files, screen recordings, certificates, forums,
 * chat, attendance, checklists and notes) and settings are removed only if
 * "Delete data on uninstall" was ticked in LMS → Settings.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('sepo_lms_payment_sweep');
wp_clear_scheduled_hook('sepo_lms_daily');
wp_unschedule_hook('sepo_lms_forum_mail');

foreach (['administrator', 'editor'] as $roleName) {
    $role = get_role($roleName);
    if ($role) {
        $role->remove_cap('manage_sepo_lms');
    }
}

$settings = get_option('sepo_lms_settings', []);

if (is_array($settings) && !empty($settings['delete_on_uninstall'])) {
    global $wpdb;
    $tables = [
        'slms_enrollments', 'slms_progress', 'slms_quiz_results', 'slms_reviews',
        'slms_submissions', 'slms_proctor', 'slms_certificates', 'slms_forum_posts',
        'slms_todos', 'slms_notes', 'slms_att_sessions', 'slms_att_records',
        'slms_chat', 'slms_chat_reads',
    ];
    foreach ($tables as $table) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB
    }
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_slms\\_qstart\\_%'"); // phpcs:ignore WordPress.DB
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_slms\\_%' OR option_name LIKE '\\_transient\\_timeout\\_slms\\_%'"); // phpcs:ignore WordPress.DB
    $uploads = wp_upload_dir(null, false);
    $private = trailingslashit((string)$uploads['basedir']) . 'sepo-lms-private';
    if (is_dir($private)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }
        @rmdir($private); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
    }
    delete_option('sepo_lms_settings');
    delete_option('sepo_lms_cert_designs');
    delete_option('sepo_lms_cert_design_default');
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_slms_cert_design'"); // phpcs:ignore WordPress.DB
    delete_option('sepo_lms_db');
    delete_option('sepo_lms_flush');
    delete_option('sepo_lms_version');
}
