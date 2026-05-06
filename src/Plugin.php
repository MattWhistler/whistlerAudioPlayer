<?php

declare(strict_types=1);

namespace AudioSummaryPlayer;

use AudioSummaryPlayer\Admin\SettingsPage;
use AudioSummaryPlayer\Block\BlockRegistration;
use AudioSummaryPlayer\Database\Schema;
use AudioSummaryPlayer\Frontend\AssetLoader;
use AudioSummaryPlayer\Frontend\Shortcode;
use AudioSummaryPlayer\REST\EventController;

/**
 * Plugin bootstrap. Wires hooks during `plugins_loaded`.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function boot(): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self();
        self::$instance->init();
    }

    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(ASP_PLUGIN_BASENAME);
            wp_die(esc_html__('Audio Summary Player requires PHP 7.4 or newer.', 'audio-summary-player'));
        }
        if (version_compare(get_bloginfo('version'), '6.0', '<')) {
            deactivate_plugins(ASP_PLUGIN_BASENAME);
            wp_die(esc_html__('Audio Summary Player requires WordPress 6.0 or newer.', 'audio-summary-player'));
        }

        Schema::migrate();

        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    private function init(): void
    {
        load_plugin_textdomain(
            'audio-summary-player',
            false,
            dirname(ASP_PLUGIN_BASENAME) . '/languages'
        );

        Schema::maybeUpgrade();

        (new BlockRegistration())->register();
        (new AssetLoader())->register();
        (new Shortcode())->register();
        (new EventController())->register();

        if (is_admin()) {
            (new SettingsPage())->register();
        }
    }
}
