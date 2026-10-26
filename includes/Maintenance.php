<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Daily housekeeping: removes proctoring recordings past their retention
 * period, closes proctoring sessions left open, and trims old chat messages
 * when chat retention is set.
 */
final class Maintenance
{
    public const CRON_HOOK = 'sepo_lms_daily';

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        }, 99);
    }

    public static function run(): void
    {
        Proctor::purgeOldRecordings((int)Settings::get('recording_retention'));
        Chat::purge((int)Settings::get('chat_retention'));
        Storage::protect();
    }
}
