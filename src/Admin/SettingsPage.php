<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Admin;

/**
 * Minimal settings screen for Stage 1.
 *
 * Only the `cta_text` option is exposed here. Stage 3 will extend this with
 * the full option set documented in spec section 13.
 */
final class SettingsPage
{
    private const OPTION_GROUP = 'asp_settings';
    private const PAGE_SLUG    = 'audio-summary-player';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('Audio Summary Player', 'audio-summary-player'),
            __('Audio Summary Player', 'audio-summary-player'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            'asp_cta_text',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => __('Odsłuchaj streszczenie', 'audio-summary-player'),
                'show_in_rest'      => false,
            ]
        );

        add_settings_section(
            'asp_section_general',
            __('Ogólne', 'audio-summary-player'),
            static function (): void {
                echo '<p>' . esc_html__('Domyślne ustawienia odtwarzacza. Pełna konfiguracja pojawi się w Etapie 3.', 'audio-summary-player') . '</p>';
            },
            self::PAGE_SLUG
        );

        add_settings_field(
            'asp_cta_text',
            __('Tekst CTA', 'audio-summary-player'),
            [$this, 'renderCtaField'],
            self::PAGE_SLUG,
            'asp_section_general'
        );
    }

    public function renderCtaField(): void
    {
        $value = (string) get_option('asp_cta_text', __('Odsłuchaj streszczenie', 'audio-summary-player'));
        printf(
            '<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" />',
            'asp_cta_text',
            esc_attr($value)
        );
        echo '<p class="description">' . esc_html__('Domyślny tekst CTA pokazywany w odtwarzaczu. Można nadpisać per artykuł w bloku.', 'audio-summary-player') . '</p>';
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Audio Summary Player', 'audio-summary-player'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
