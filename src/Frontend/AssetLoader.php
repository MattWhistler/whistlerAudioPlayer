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

        [$jsUrl, ]  = $this->resolveAsset('player.js');
        [$cssUrl, ] = $this->resolveAsset('player.css');

        // Cache-busting: WordPress dokleja `?ver=ASP_VERSION` do URL-a.
        // Bump wersji wtyczki przy każdym commicie (patrz CLAUDE.md 4.4)
        // unieważnia cache przeglądarek/CDN dla player.js i player.css.
        wp_enqueue_style(
            self::HANDLE_STYLE,
            $cssUrl,
            [],
            ASP_VERSION
        );

        $accent = (string) get_option('asp_accent_color', '#185fa5');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            wp_add_inline_style(self::HANDLE_STYLE, ':root{--asp-accent:' . $accent . ';}');
        }

        wp_enqueue_script(
            self::HANDLE_SCRIPT,
            $jsUrl,
            [],
            ASP_VERSION,
            true
        );

        wp_set_script_translations(self::HANDLE_SCRIPT, 'audio-summary-player');

        wp_localize_script(self::HANDLE_SCRIPT, 'aspConfig', [
            'restUrl'     => esc_url_raw(rest_url('asp/v1/event')),
            'reactionUrl' => esc_url_raw(rest_url('asp/v1/reaction')),
            'statsUrl'    => esc_url_raw(rest_url('asp/v1/post-stats')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'debug'       => defined('WP_DEBUG') && WP_DEBUG,
            'i18n'        => [
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
