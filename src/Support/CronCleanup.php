<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Support;

use AudioSummaryPlayer\Database\StatsRepository;

/**
 * Daily WP-Cron job that prunes events older than `asp_data_retention_days`.
 *
 * The cron event is registered on plugin activation and unregistered on
 * deactivation. The retention window can be `0` (forever) which short-circuits
 * the delete call.
 */
final class CronCleanup
{
    public const HOOK = 'asp_cleanup_old_events';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function run(): void
    {
        $days = (int) get_option('asp_data_retention_days', 180);
        if ($days <= 0) {
            // 0 / negative ⇒ "keep forever".
            return;
        }
        $beforeUtc = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        (new StatsRepository())->deleteOlderThan($beforeUtc);
    }
}
