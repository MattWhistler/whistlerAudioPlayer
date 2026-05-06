<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Support;

/**
 * Heuristic bot detection. Used at insert time to flag `is_bot=1`.
 * Bots are NOT blocked — they are tagged so aggregation queries can filter
 * them out by default while raw exports still see them.
 *
 * The User-Agent string itself is never persisted (RODO).
 */
final class BotDetector
{
    /**
     * Default whitelist of well-known bot tokens, matched case-insensitively
     * as substrings of the UA. Customizable via filter `asp_bot_user_agents`.
     *
     * @return string[]
     */
    public static function tokens(): array
    {
        $defaults = [
            'googlebot',
            'bingbot',
            'yandex',
            'duckduckbot',
            'baiduspider',
            'ahrefsbot',
            'semrushbot',
            'mj12bot',
            'dotbot',
            'rogerbot',
            'facebookexternalhit',
            'facebot',
            'twitterbot',
            'linkedinbot',
            'slackbot',
            'telegrambot',
            'applebot',
            'petalbot',
            'bytespider',
            'gptbot',
            'claudebot',
            'ccbot',
            'perplexitybot',
        ];

        $extra = (string) get_option('asp_excluded_user_agents', '');
        if ($extra !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $extra) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $defaults[] = $line;
                }
            }
        }

        /** @var string[] $list */
        $list = apply_filters('asp_bot_user_agents', $defaults);
        return array_values(array_filter(array_map('strtolower', array_map('strval', $list))));
    }

    public static function isBot(?string $userAgent): bool
    {
        if ($userAgent === null) {
            return true;
        }
        $ua = trim($userAgent);
        if ($ua === '' || strlen($ua) < 20) {
            return true;
        }
        $haystack = strtolower($ua);
        foreach (self::tokens() as $token) {
            if ($token !== '' && strpos($haystack, $token) !== false) {
                return true;
            }
        }
        // Generic heuristics — last so explicit whitelist wins on naming.
        foreach (['bot', 'crawler', 'spider', 'scraper', 'headlesschrome'] as $kw) {
            if (strpos($haystack, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
}
