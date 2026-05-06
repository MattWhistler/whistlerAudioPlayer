<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Frontend;

/**
 * Enqueues the player runtime assets.
 *
 * Stage 1 keeps it simple: assets ship as already-built `player.js` / `player.css`
 * so the plugin works without `npm run build` for early iteration. The build
 * pipeline can produce the same filenames into `assets/build/` later — the
 * loader prefers the build output when present.
 */
final class AssetLoader
{
    private const HANDLE_SCRIPT = 'asp-player';
    private const HANDLE_STYLE  = 'asp-player';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (!$this->shouldEnqueue()) {
            return;
        }

        [$jsUrl, $jsPath]   = $this->resolveAsset('player.js');
        [$cssUrl, $cssPath] = $this->resolveAsset('player.css');

        $jsVersion  = $jsPath !== null ? (string) filemtime($jsPath) : ASP_VERSION;
        $cssVersion = $cssPath !== null ? (string) filemtime($cssPath) : ASP_VERSION;

        wp_enqueue_style(
            self::HANDLE_STYLE,
            $cssUrl,
            [],
            $cssVersion
        );

        wp_enqueue_script(
            self::HANDLE_SCRIPT,
            $jsUrl,
            [],
            $jsVersion,
            true
        );

        wp_set_script_translations(self::HANDLE_SCRIPT, 'audio-summary-player');

        wp_localize_script(self::HANDLE_SCRIPT, 'aspConfig', [
            'restUrl'   => esc_url_raw(rest_url('asp/v1/event')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'debug'     => defined('WP_DEBUG') && WP_DEBUG,
            'i18n'      => [
                'play'        => __('Odtwórz streszczenie audio', 'audio-summary-player'),
                'pause'       => __('Wstrzymaj odtwarzanie', 'audio-summary-player'),
                'speedLabel'  => __('Prędkość odtwarzania, aktualnie %sx', 'audio-summary-player'),
            ],
        ]);
    }

    private function shouldEnqueue(): bool
    {
        if (is_admin()) {
            return false;
        }
        if (is_singular() && has_block('audio-summary-player/player')) {
            return true;
        }
        // Classic editor / shortcode usage: the shortcode handler will set a flag.
        if (!empty($GLOBALS['asp_shortcode_used'])) {
            return true;
        }
        return false;
    }

    /**
     * @return array{0:string,1:?string} URL + absolute path (path null if not found on disk).
     */
    private function resolveAsset(string $filename): array
    {
        $buildPath = ASP_PLUGIN_DIR . 'assets/build/' . $filename;
        if (file_exists($buildPath)) {
            return [ASP_PLUGIN_URL . 'assets/build/' . $filename, $buildPath];
        }
        $srcPath = ASP_PLUGIN_DIR . 'assets/src/' . $filename;
        if (file_exists($srcPath)) {
            return [ASP_PLUGIN_URL . 'assets/src/' . $filename, $srcPath];
        }
        return [ASP_PLUGIN_URL . 'assets/build/' . $filename, null];
    }
}
