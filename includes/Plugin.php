<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Wires every part of the plugin into WordPress.
 */
final class Plugin
{
    public static function boot(): void
    {
        load_plugin_textdomain('sepo-lms', false, dirname(plugin_basename(SLMS_FILE)) . '/languages');

        Activator::maybeUpgrade();

        PostTypes::init();
        Rest::init();
        Payments::init();
        Receipt::init();
        Frontend::init();
        Privacy::init();

        if (is_admin()) {
            Admin\Menu::init();
            Admin\MetaBoxes::init();
            Admin\QuizBuilder::init();
            Admin\Columns::init();
            Admin\EnrollmentsPage::init();
            Admin\ResultsPage::init();
            Admin\SettingsPage::init();
        }

        // Rewrite rules are flushed once, after activation or an address change.
        add_action('init', static function (): void {
            if (get_option(Activator::FLUSH_OPTION)) {
                delete_option(Activator::FLUSH_OPTION);
                flush_rewrite_rules(false);
            }
            if (!wp_next_scheduled(Payments::CRON_HOOK)) {
                wp_schedule_event(time() + 300, 'hourly', Payments::CRON_HOOK);
            }
        }, 99);
    }
}
