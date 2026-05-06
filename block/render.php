<?php
/**
 * Server-side render for audio-summary-player/player block.
 *
 * @var array       $attributes Block attributes.
 * @var string      $content    Inner content (none — save returns null).
 * @var WP_Block    $block      Block instance.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

echo \AudioSummaryPlayer\Frontend\PlayerMarkup::render(
    is_array($attributes) ? $attributes : [],
    isset($block) && $block instanceof WP_Block ? (int) ($block->context['postId'] ?? get_the_ID()) : (int) get_the_ID()
);
