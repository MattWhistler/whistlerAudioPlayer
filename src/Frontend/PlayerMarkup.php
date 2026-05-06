<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Frontend;

/**
 * Generates the HTML markup for the audio player.
 *
 * The markup is intentionally self-describing through `data-*` attributes so
 * the runtime JS can attach without extra server-side wiring.
 */
final class PlayerMarkup
{
    /**
     * @param array<string,mixed> $attributes Block attributes.
     */
    public static function render(array $attributes, int $postId): string
    {
        $audioUrl = isset($attributes['audioUrl']) ? (string) $attributes['audioUrl'] : '';
        if ($audioUrl === '') {
            return self::renderMissingAudio();
        }

        $audioId       = isset($attributes['audioId']) ? (int) $attributes['audioId'] : 0;
        $audioDuration = isset($attributes['audioDuration']) ? (float) $attributes['audioDuration'] : 0.0;
        $ctaOverride   = isset($attributes['ctaText']) ? trim((string) $attributes['ctaText']) : '';

        $defaultCta = (string) get_option('asp_cta_text', __('Odsłuchaj streszczenie', 'audio-summary-player'));
        $ctaText    = $ctaOverride !== '' ? $ctaOverride : $defaultCta;

        $title = $postId > 0 ? get_the_title($postId) : '';
        if ($title === '') {
            $title = $ctaText;
        }

        $minDuration = (int) get_option('asp_minibar_min_duration', 30);
        $miniEnabled = (bool) get_option('asp_enable_minibar', true);
        $speedEnabled = (bool) get_option('asp_enable_speed_control', true);

        $playerId = 'asp-player-' . ($postId > 0 ? $postId : wp_rand(1000, 9999));

        ob_start();
        ?>
        <div
            class="asp-player"
            id="<?php echo esc_attr($playerId); ?>"
            data-asp-player
            data-post-id="<?php echo esc_attr((string) $postId); ?>"
            data-audio-id="<?php echo esc_attr((string) $audioId); ?>"
            data-audio-duration="<?php echo esc_attr((string) $audioDuration); ?>"
            data-minibar-enabled="<?php echo $miniEnabled ? '1' : '0'; ?>"
            data-minibar-min-duration="<?php echo esc_attr((string) $minDuration); ?>"
            data-speed-enabled="<?php echo $speedEnabled ? '1' : '0'; ?>"
        >
            <button
                type="button"
                class="asp-player__play"
                data-asp-action="toggle"
                aria-label="<?php echo esc_attr__('Odtwórz streszczenie audio', 'audio-summary-player'); ?>"
            >
                <span class="asp-player__icon asp-player__icon--play" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>
                </span>
                <span class="asp-player__icon asp-player__icon--pause" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M6 5h4v14H6zM14 5h4v14h-4z" fill="currentColor"/></svg>
                </span>
            </button>

            <div class="asp-player__body">
                <div class="asp-player__text-wrap" aria-live="polite">
                    <span
                        class="asp-player__text is-active"
                        data-asp-text="cta"
                        data-asp-cta="<?php echo esc_attr($ctaText); ?>"
                        data-asp-title="<?php echo esc_attr($title); ?>"
                    ><?php echo esc_html($ctaText); ?></span>
                </div>

                <div
                    class="asp-player__progress"
                    data-asp-action="seek"
                    role="slider"
                    tabindex="0"
                    aria-label="<?php echo esc_attr__('Pozycja w nagraniu', 'audio-summary-player'); ?>"
                    aria-valuemin="0"
                    aria-valuemax="<?php echo esc_attr((string) max(0, (int) $audioDuration)); ?>"
                    aria-valuenow="0"
                >
                    <div class="asp-player__progress-fill" data-asp-progress-fill style="width:0%"></div>
                </div>

                <div class="asp-player__times">
                    <span data-asp-time="current">0:00</span>
                    <span data-asp-time="total"><?php echo $audioDuration > 0 ? esc_html(self::formatTime($audioDuration)) : '–:––'; ?></span>
                </div>
            </div>

            <?php if ($speedEnabled) : ?>
            <button
                type="button"
                class="asp-player__speed"
                data-asp-action="speed"
                aria-label="<?php echo esc_attr__('Prędkość odtwarzania, aktualnie 1x', 'audio-summary-player'); ?>"
            >
                <span data-asp-speed-label>1×</span>
            </button>
            <?php endif; ?>

            <audio
                preload="metadata"
                data-asp-audio
                src="<?php echo esc_url($audioUrl); ?>"
            ></audio>
        </div>

        <?php if ($postId > 0) : ?>
        <div class="asp-meta" data-asp-meta data-post-id="<?php echo esc_attr((string) $postId); ?>">
            <span class="asp-meta__plays" data-asp-plays>
                <span class="asp-meta__plays-value" data-asp-plays-value>—</span>
                <span class="asp-meta__plays-label"><?php esc_html_e('odtworzeń', 'audio-summary-player'); ?></span>
            </span>
            <span class="asp-meta__reactions" data-asp-reactions>
                <button
                    type="button"
                    class="asp-meta__reaction"
                    data-asp-reaction="like"
                    aria-pressed="false"
                    aria-label="<?php echo esc_attr__('Lubię to streszczenie', 'audio-summary-player'); ?>"
                >
                    <span aria-hidden="true">👍</span>
                    <span class="asp-meta__reaction-count" data-asp-likes>0</span>
                </button>
                <button
                    type="button"
                    class="asp-meta__reaction"
                    data-asp-reaction="dislike"
                    aria-pressed="false"
                    aria-label="<?php echo esc_attr__('Nie lubię tego streszczenia', 'audio-summary-player'); ?>"
                >
                    <span aria-hidden="true">👎</span>
                    <span class="asp-meta__reaction-count" data-asp-dislikes>0</span>
                </button>
            </span>
        </div>
        <?php endif; ?>

        <?php if ($miniEnabled) : ?>
        <aside
            class="asp-minibar"
            data-asp-minibar
            data-target="<?php echo esc_attr($playerId); ?>"
            aria-hidden="true"
        >
            <button
                type="button"
                class="asp-minibar__play"
                data-asp-action="toggle"
                aria-label="<?php echo esc_attr__('Odtwórz / wstrzymaj w mini-odtwarzaczu', 'audio-summary-player'); ?>"
            >
                <svg class="asp-minibar__ring" viewBox="0 0 36 36" aria-hidden="true">
                    <circle class="asp-minibar__ring-track" cx="18" cy="18" r="16" />
                    <circle class="asp-minibar__ring-fill" cx="18" cy="18" r="16" data-asp-ring />
                </svg>
                <span class="asp-minibar__icon asp-minibar__icon--play" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>
                </span>
                <span class="asp-minibar__icon asp-minibar__icon--pause" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="14" height="14"><path d="M6 5h4v14H6zM14 5h4v14h-4z" fill="currentColor"/></svg>
                </span>
            </button>
            <span class="asp-minibar__title"><?php echo esc_html($title); ?></span>
            <button
                type="button"
                class="asp-minibar__close"
                data-asp-action="dismiss"
                aria-label="<?php echo esc_attr__('Ukryj mini-odtwarzacz', 'audio-summary-player'); ?>"
            >
                <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </aside>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderMissingAudio(): string
    {
        return sprintf(
            '<div class="asp-player asp-player--missing">%s</div>',
            esc_html__('Nie można załadować pliku audio.', 'audio-summary-player')
        );
    }

    private static function formatTime(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
    }
}
