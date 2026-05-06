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
        $built = ASP_PLUGIN_DIR . 'block/build';
        $source = ASP_PLUGIN_DIR . 'block';
        $path = is_dir($built) && file_exists($built . '/block.json') ? $built : $source;

        register_block_type(
            $path,
            [
                'render_callback' => [BlockRenderer::class, 'render'],
            ]
        );
    }
}
