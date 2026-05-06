<?php
/**
 * Uninstall handler for Audio Summary Player.
 *
 * Triggered by WordPress when the user deletes the plugin from the admin.
 *
 * Behaviour gated by the `asp_keep_data_on_uninstall` option (spec sec. 9.5):
 *  - false (default): drop the events table AND wipe every `asp_*` option/transient.
 *  - true: keep the events table and historical options, only clear the cron and
 *    transient cache so a future re-install boots clean.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$keep_data = (bool) get_option('asp_keep_data_on_uninstall', false);

// Always clear scheduled cron — the hook is gone with the plugin code.
$timestamp = wp_next_scheduled('asp_cleanup_old_events');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'asp_cleanup_old_events');
}
wp_clear_scheduled_hook('asp_cleanup_old_events');

if (!$keep_data) {
    $eventsTable = $wpdb->prefix . 'asp_events';
    $reactionsTable = $wpdb->prefix . 'asp_reactions';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names controlled.
    $wpdb->query("DROP TABLE IF EXISTS {$eventsTable}");
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names controlled.
    $wpdb->query("DROP TABLE IF EXISTS {$reactionsTable}");
}

$asp_options = [
    'asp_db_version',
    'asp_cta_text',
    'asp_accent_color',
    'asp_enable_minibar',
    'asp_minibar_min_duration',
    'asp_enable_speed_control',
    'asp_data_retention_days',
    'asp_keep_data_on_uninstall',
    'asp_stats_capability',
    'asp_excluded_user_agents',
];

foreach ($asp_options as $option) {
    if ($keep_data && $option === 'asp_keep_data_on_uninstall') {
        // Preserve user's preference so re-install respects it.
        continue;
    }
    delete_option($option);
}

// Best-effort: scrub any leftover transients & metabox caches.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_asp_') . '%',
        $wpdb->esc_like('_transient_timeout_asp_') . '%'
    )
);
