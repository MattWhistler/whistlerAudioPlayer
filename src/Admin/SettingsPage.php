<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Admin;

/**
 * `Settings → Audio Summary Player` — exposes every option from spec sec. 13.
 *
 * Each option uses an explicit `sanitize_callback` so we never trust raw POST
 * data. Defaults are also defined here so a fresh install renders sane values
 * even before the user visits the page.
 */
final class SettingsPage
{
    private const OPTION_GROUP = 'asp_settings';
    private const PAGE_SLUG    = 'audio-summary-player';

    private const RETENTION_CHOICES = [30, 90, 180, 365, 0];
    private const CAP_CHOICES = ['manage_options', 'edit_others_posts', 'edit_posts'];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
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

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        // Tiny inline init for the color picker — avoids a separate file.
        wp_add_inline_script(
            'wp-color-picker',
            'jQuery(function($){ $(".asp-color-picker").wpColorPicker(); });'
        );
    }

    public function registerSettings(): void
    {
        $defaultCta = __('Odsłuchaj streszczenie', 'audio-summary-player');

        $options = [
            ['asp_cta_text', 'string', [$this, 'sanitizeCta'], $defaultCta],
            ['asp_accent_color', 'string', [$this, 'sanitizeColor'], '#185fa5'],
            ['asp_enable_minibar', 'boolean', [$this, 'sanitizeBool'], true],
            ['asp_minibar_min_duration', 'integer', [$this, 'sanitizeMinDuration'], 30],
            ['asp_enable_speed_control', 'boolean', [$this, 'sanitizeBool'], true],
            ['asp_data_retention_days', 'integer', [$this, 'sanitizeRetention'], 180],
            ['asp_keep_data_on_uninstall', 'boolean', [$this, 'sanitizeBool'], false],
            ['asp_stats_capability', 'string', [$this, 'sanitizeCapability'], 'manage_options'],
            ['asp_excluded_user_agents', 'string', [$this, 'sanitizeExcludedUa'], ''],
        ];

        foreach ($options as [$name, $type, $sanitize, $default]) {
            register_setting(
                self::OPTION_GROUP,
                $name,
                [
                    'type'              => $type,
                    'sanitize_callback' => $sanitize,
                    'default'           => $default,
                    'show_in_rest'      => false,
                ]
            );
        }

        add_settings_section(
            'asp_section_general',
            __('Wyświetlanie', 'audio-summary-player'),
            '__return_false',
            self::PAGE_SLUG
        );

        $this->addField('asp_cta_text', __('Tekst CTA', 'audio-summary-player'), 'asp_section_general', 'renderCta');
        $this->addField('asp_accent_color', __('Kolor akcentu', 'audio-summary-player'), 'asp_section_general', 'renderAccent');
        $this->addField('asp_enable_minibar', __('Mini-bar (sticky)', 'audio-summary-player'), 'asp_section_general', 'renderMinibar');
        $this->addField('asp_minibar_min_duration', __('Min. długość audio (s)', 'audio-summary-player'), 'asp_section_general', 'renderMinDuration');
        $this->addField('asp_enable_speed_control', __('Kontrola prędkości', 'audio-summary-player'), 'asp_section_general', 'renderSpeed');

        add_settings_section(
            'asp_section_data',
            __('Dane i prywatność', 'audio-summary-player'),
            '__return_false',
            self::PAGE_SLUG
        );
        $this->addField('asp_data_retention_days', __('Retencja danych', 'audio-summary-player'), 'asp_section_data', 'renderRetention');
        $this->addField('asp_keep_data_on_uninstall', __('Zachowaj dane przy odinstalowaniu', 'audio-summary-player'), 'asp_section_data', 'renderKeepData');
        $this->addField('asp_excluded_user_agents', __('Dodatkowe UA botów', 'audio-summary-player'), 'asp_section_data', 'renderExcludedUa');

        add_settings_section(
            'asp_section_perms',
            __('Uprawnienia', 'audio-summary-player'),
            '__return_false',
            self::PAGE_SLUG
        );
        $this->addField('asp_stats_capability', __('Uprawnienie do statystyk', 'audio-summary-player'), 'asp_section_perms', 'renderCapability');
    }

    private function addField(string $id, string $label, string $section, string $renderMethod): void
    {
        add_settings_field($id, $label, [$this, $renderMethod], self::PAGE_SLUG, $section);
    }

    /* ---------- sanitizers ---------- */

    public function sanitizeCta($value): string
    {
        return sanitize_text_field((string) $value);
    }

    public function sanitizeColor($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }
        return '#185fa5';
    }

    public function sanitizeBool($value): bool
    {
        return !empty($value);
    }

    public function sanitizeMinDuration($value): int
    {
        $n = (int) $value;
        return $n < 1 ? 30 : min($n, 3600);
    }

    public function sanitizeRetention($value): int
    {
        $n = (int) $value;
        return in_array($n, self::RETENTION_CHOICES, true) ? $n : 180;
    }

    public function sanitizeCapability($value): string
    {
        $value = is_string($value) ? $value : '';
        return in_array($value, self::CAP_CHOICES, true) ? $value : 'manage_options';
    }

    public function sanitizeExcludedUa($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(sanitize_text_field($line));
            if ($line !== '') {
                $clean[] = $line;
            }
        }
        return implode("\n", array_slice($clean, 0, 100));
    }

    /* ---------- field renderers ---------- */

    public function renderCta(): void
    {
        $value = (string) get_option('asp_cta_text', __('Odsłuchaj streszczenie', 'audio-summary-player'));
        printf(
            '<input type="text" name="asp_cta_text" value="%s" class="regular-text" />',
            esc_attr($value)
        );
        echo '<p class="description">' . esc_html__('Domyślny tekst CTA pokazywany w odtwarzaczu. Można nadpisać per artykuł w bloku.', 'audio-summary-player') . '</p>';
    }

    public function renderAccent(): void
    {
        $value = (string) get_option('asp_accent_color', '#185fa5');
        printf(
            '<input type="text" name="asp_accent_color" value="%s" class="asp-color-picker" data-default-color="#185fa5" />',
            esc_attr($value)
        );
    }

    public function renderMinibar(): void
    {
        $value = (bool) get_option('asp_enable_minibar', true);
        printf(
            '<label><input type="checkbox" name="asp_enable_minibar" value="1" %s /> %s</label>',
            checked($value, true, false),
            esc_html__('Pokazuj sticky mini-odtwarzacz przy scrollu', 'audio-summary-player')
        );
    }

    public function renderMinDuration(): void
    {
        $value = (int) get_option('asp_minibar_min_duration', 30);
        printf(
            '<input type="number" min="1" max="3600" name="asp_minibar_min_duration" value="%d" class="small-text" />',
            $value
        );
        echo ' <span class="description">' . esc_html__('Mini-bar pojawia się tylko gdy nagranie jest dłuższe.', 'audio-summary-player') . '</span>';
    }

    public function renderSpeed(): void
    {
        $value = (bool) get_option('asp_enable_speed_control', true);
        printf(
            '<label><input type="checkbox" name="asp_enable_speed_control" value="1" %s /> %s</label>',
            checked($value, true, false),
            esc_html__('Przycisk 1× / 1.25× / 1.5× / 2×', 'audio-summary-player')
        );
    }

    public function renderRetention(): void
    {
        $value = (int) get_option('asp_data_retention_days', 180);
        $choices = [
            30  => __('30 dni', 'audio-summary-player'),
            90  => __('90 dni', 'audio-summary-player'),
            180 => __('180 dni (domyślnie)', 'audio-summary-player'),
            365 => __('365 dni', 'audio-summary-player'),
            0   => __('Nigdy nie czyść', 'audio-summary-player'),
        ];
        echo '<select name="asp_data_retention_days">';
        foreach ($choices as $days => $label) {
            printf(
                '<option value="%d" %s>%s</option>',
                $days,
                selected($value, $days, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Codzienny cron czyści eventy starsze niż wybrany okres.', 'audio-summary-player') . '</p>';
    }

    public function renderKeepData(): void
    {
        $value = (bool) get_option('asp_keep_data_on_uninstall', false);
        printf(
            '<label><input type="checkbox" name="asp_keep_data_on_uninstall" value="1" %s /> %s</label>',
            checked($value, true, false),
            esc_html__('Nie usuwaj tabeli eventów przy odinstalowaniu wtyczki', 'audio-summary-player')
        );
    }

    public function renderExcludedUa(): void
    {
        $value = (string) get_option('asp_excluded_user_agents', '');
        printf(
            '<textarea name="asp_excluded_user_agents" rows="4" cols="50" class="large-text code">%s</textarea>',
            esc_textarea($value)
        );
        echo '<p class="description">' . esc_html__('Po jednym fragmencie UA na linię. UA zawierający dany fragment będzie oznaczony jako bot.', 'audio-summary-player') . '</p>';
    }

    public function renderCapability(): void
    {
        $value = (string) get_option('asp_stats_capability', 'manage_options');
        $choices = [
            'manage_options'    => __('Administrator (manage_options)', 'audio-summary-player'),
            'edit_others_posts' => __('Redaktor (edit_others_posts)', 'audio-summary-player'),
            'edit_posts'        => __('Autor (edit_posts)', 'audio-summary-player'),
        ];
        echo '<select name="asp_stats_capability">';
        foreach ($choices as $cap => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($cap),
                selected($value, $cap, false),
                esc_html($label)
            );
        }
        echo '</select>';
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
