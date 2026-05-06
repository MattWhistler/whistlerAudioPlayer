<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Block;

use AudioSummaryPlayer\Frontend\PlayerMarkup;
use WP_Block;

/**
 * Render callback for the player block.
 *
 * Server-side render is required because we need access to the current post
 * title (`get_the_title()`) and we want to evolve markup without rewriting
 * `post_content` on every release.
 */
final class BlockRenderer
{
    /**
     * @param array<string,mixed> $attributes
     */
    public static function render(array $attributes, string $content = '', ?WP_Block $block = null): string
    {
        $postId = 0;
        if ($block instanceof WP_Block && isset($block->context['postId'])) {
            $postId = (int) $block->context['postId'];
        }
        if ($postId === 0) {
            $postId = (int) get_the_ID();
        }

        return PlayerMarkup::render($attributes, $postId);
    }
}
