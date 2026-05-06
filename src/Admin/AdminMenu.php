<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Admin;

/**
 * Owns the top-level "Audio Player" admin menu.
 *
 * The parent menu is registered with priority 9 so submenu pages added in
 * SettingsPage / StatsPage at the default priority 10 attach to it.
 */
final class AdminMenu
{
    public const PARENT_SLUG = 'audio-summary-player';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 9);
    }

    public function addMenu(): void
    {
        $cap = (string) get_option('asp_stats_capability', 'manage_options');

        add_menu_page(
            __('Audio Player', 'audio-summary-player'),
            __('Audio Player', 'audio-summary-player'),
            $cap,
            self::PARENT_SLUG,
            '__return_null',
            'dashicons-controls-volumeon',
            58
        );
    }
}
