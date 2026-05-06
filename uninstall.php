<?php
/**
 * Uninstall handler for Audio Summary Player.
 *
 * Triggered by WordPress when the user deletes the plugin from the admin.
 * Stage 1: only removes plugin options. Stage 2/3 will add table cleanup
 * gated by the `asp_keep_data_on_uninstall` option.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
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

$keep_data = (bool) get_option('asp_keep_data_on_uninstall', false);

foreach ($asp_options as $option) {
    if ($keep_data && $option === 'asp_keep_data_on_uninstall') {
        continue;
    }
    delete_option($option);
}
