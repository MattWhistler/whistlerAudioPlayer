<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Block;

/**
 * Registers the Gutenberg block via block.json metadata.
 */
final class BlockRegistration
{
    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock(): void
    {
        register_block_type(
            ASP_PLUGIN_DIR . 'block',
            [
                'render_callback' => [BlockRenderer::class, 'render'],
            ]
        );
    }
}
