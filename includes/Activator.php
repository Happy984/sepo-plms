<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Activation, deactivation and schema upgrades.
 */
final class Activator
{
    public const DB_VERSION = '1';
    public const CAP = 'manage_sepo_lms';
    public const DB_OPTION = 'sepo_lms_db';
    public const FLUSH_OPTION = 'sepo_lms_flush';

    public static function activate(): void
    {
        self::install();

        foreach (['administrator', 'editor'] as $roleName) {
            $role = get_role($roleName);
            if ($role && !$role->has_cap(self::CAP)) {
                $role->add_cap(self::CAP);
            }
        }

        self::ensureMyCoursesPage();

        if (!wp_next_scheduled(Payments::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', Payments::CRON_HOOK);
        }

        // Post types register on init; flush once they exist.
        update_option(self::FLUSH_OPTION, 1, false);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Payments::CRON_HOOK);
        flush_rewrite_rules(false);
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}slms_enrollments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reference varchar(40) NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(160) NOT NULL DEFAULT '',
  national_id varchar(60) NOT NULL DEFAULT '',
  gender varchar(30) NOT NULL DEFAULT '',
  location varchar(160) NOT NULL DEFAULT '',
  dob varchar(20) NOT NULL DEFAULT '',
  phone varchar(30) NOT NULL DEFAULT '',
  level varchar(60) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  method_id varchar(60) NOT NULL DEFAULT '',
  method varchar(120) NOT NULL DEFAULT '',
  method_type varchar(20) NOT NULL DEFAULT '',
  currency varchar(3) NOT NULL DEFAULT '',
  base_price decimal(12,2) NOT NULL DEFAULT 0,
  discount_rate decimal(6,2) NOT NULL DEFAULT 0,
  discount_label varchar(60) NOT NULL DEFAULT '',
  discount_amount decimal(12,2) NOT NULL DEFAULT 0,
  unit_price decimal(12,2) NOT NULL DEFAULT 0,
  tax_rate decimal(6,2) NOT NULL DEFAULT 0,
  tax_type varchar(30) NOT NULL DEFAULT '',
  tax_country varchar(60) NOT NULL DEFAULT '',
  tax_amount decimal(12,2) NOT NULL DEFAULT 0,
  quantity int(10) unsigned NOT NULL DEFAULT 1,
  amount decimal(12,2) NOT NULL DEFAULT 0,
  gateway varchar(20) NOT NULL DEFAULT '',
  gateway_ref varchar(191) NOT NULL DEFAULT '',
  gateway_status varchar(40) NOT NULL DEFAULT '',
  created datetime NOT NULL,
  updated datetime NOT NULL,
  paid_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  UNIQUE KEY course_user (course_id,user_id),
  KEY user_id (user_id),
  KEY status (status),
  KEY gateway_ref (gateway_ref)
) $c;");

        dbDelta("CREATE TABLE {$p}slms_progress (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  done_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY course_user_item (course_id,user_id,item_id),
  KEY user_id (user_id)
) $c;");

        dbDelta("CREATE TABLE {$p}slms_quiz_results (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quiz_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  guest varchar(64) NOT NULL DEFAULT '',
  email varchar(160) NOT NULL DEFAULT '',
  score int(11) NOT NULL DEFAULT 0,
  passed tinyint(1) NOT NULL DEFAULT 0,
  total_questions int(11) NOT NULL DEFAULT 0,
  answered int(11) NOT NULL DEFAULT 0,
  correct int(11) NOT NULL DEFAULT 0,
  points_earned decimal(10,2) NOT NULL DEFAULT 0,
  points_total decimal(10,2) NOT NULL DEFAULT 0,
  weight decimal(6,2) NOT NULL DEFAULT 0,
  answers longtext NULL,
  results longtext NULL,
  attempt int(11) NOT NULL DEFAULT 1,
  best_score int(11) NOT NULL DEFAULT 0,
  submitted_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY quiz_user (quiz_id,user_id,guest),
  KEY user_id (user_id),
  KEY course_id (course_id)
) $c;");

        dbDelta("CREATE TABLE {$p}slms_reviews (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL DEFAULT '',
  rating tinyint(3) unsigned NOT NULL DEFAULT 5,
  body text NOT NULL,
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY course_user (course_id,user_id),
  KEY course_id (course_id)
) $c;");

        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    /** One "My courses" page holding the shortcode, created once and remembered in the settings. */
    private static function ensureMyCoursesPage(): void
    {
        $id = (int)Settings::get('my_courses_page');
        if ($id > 0 && get_post_status($id) !== false && get_post_status($id) !== 'trash') {
            return;
        }
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('My courses', 'sepo-lms'),
            'post_name'    => 'my-courses',
            'post_content' => '<!-- wp:shortcode -->[sepo_lms_my_courses]<!-- /wp:shortcode -->',
        ]);
        if (is_int($id) && $id > 0) {
            $all = get_option(Settings::OPTION, []);
            $all = is_array($all) ? $all : [];
            $all['my_courses_page'] = $id;
            update_option(Settings::OPTION, $all);
            Settings::flush();
        }
    }
}
