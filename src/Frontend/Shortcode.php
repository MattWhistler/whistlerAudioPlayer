<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Frontend;

/**
 * Classic Editor fallback shortcode: `[asp_player audio_id="123"]`.
 *
 * Provides parity with the Gutenberg block for sites that have not migrated
 * from Classic Editor. Uses the same PlayerMarkup so behavior stays identical.
 */
final class Shortcode
{
    public function register(): void
    {
        add_shortcode('asp_player', [$this, 'render']);
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render($atts): string
    {
        $atts = shortcode_atts(
            [
                'audio_id' => 0,
                'cta'      => '',
            ],
            is_array($atts) ? $atts : [],
            'asp_player'
        );

        $audioId = (int) $atts['audio_id'];
        if ($audioId <= 0) {
            return '';
        }

        $audioUrl = (string) wp_get_attachment_url($audioId);
        if ($audioUrl === '') {
            return '';
        }

        $meta = wp_get_attachment_metadata($audioId);
        $duration = isset($meta['length']) ? (float) $meta['length'] : 0.0;

        $GLOBALS['asp_shortcode_used'] = true;

        // Late enqueue: in case action `wp_enqueue_scripts` already fired.
        if (did_action('wp_enqueue_scripts')) {
            (new AssetLoader())->enqueue();
        }

        return PlayerMarkup::render(
            [
                'audioId'       => $audioId,
                'audioUrl'      => $audioUrl,
                'audioDuration' => $duration,
                'ctaText'       => (string) $atts['cta'],
            ],
            (int) get_the_ID()
        );
    }
}
