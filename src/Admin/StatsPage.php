<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Admin;

use AudioSummaryPlayer\Database\StatsRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `Tools → Statystyki Audio Player` — aggregated dashboard for all posts.
 *
 * Filters: date range (7/30/90/180/custom), bots toggle, sort, post_id (when
 * deep-linked from the metabox).
 *
 * Charts are rendered as inline SVG (line + pie) so we don't pull a chart
 * library — keeps admin assets light and CSP-friendly.
 *
 * Capability: configurable via `asp_stats_capability` option (default
 * `manage_options`, spec sec. 12.4).
 */
final class StatsPage
{
    public const PAGE_SLUG = 'audio-summary-player';

    private const PER_PAGE = 20;
    private const SPEED_COLORS = [
        '1.00' => '#185fa5',
        '1.25' => '#2596be',
        '1.50' => '#f2a900',
        '2.00' => '#d63638',
    ];

    private StatsRepository $stats;

    public function __construct(?StatsRepository $stats = null)
    {
        $this->stats = $stats ?? new StatsRepository();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'asp/v1',
            '/details',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'restDetails'],
                'permission_callback' => [$this, 'restPermission'],
                'args'                => [
                    'post_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                    'since'   => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'bots'    => ['required' => false, 'sanitize_callback' => 'absint'],
                ],
            ]
        );
    }

    public function restPermission(WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('x_wp_nonce');
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }
        $cap = (string) get_option('asp_stats_capability', 'manage_options');
        return current_user_can($cap);
    }

    /**
     * @return WP_REST_Response
     */
    public function restDetails(WP_REST_Request $request)
    {
        $postId      = (int) $request->get_param('post_id');
        $since       = (string) $request->get_param('since');
        $includeBots = (bool) $request->get_param('bots');

        if ($since === '') {
            $since = StatsRepository::rangeToSinceUtc('30');
        }

        $funnel = $this->stats->funnelForPost($postId, $since, $includeBots);
        $avg    = $this->stats->avgListenSecondsForPost($postId, $since, $includeBots);
        $events = $this->stats->recentEventsForPost($postId, 50, $includeBots);

        return new WP_REST_Response([
            'post_id'    => $postId,
            'post_title' => (string) get_the_title($postId),
            'funnel'     => $funnel,
            'avg_listen' => round($avg, 1),
            'events'     => $events,
        ], 200);
    }

    public function addMenu(): void
    {
        $cap = (string) get_option('asp_stats_capability', 'manage_options');
        add_submenu_page(
            AdminMenu::PARENT_SLUG,
            __('Statystyki', 'audio-summary-player'),
            __('Statystyki', 'audio-summary-player'),
            $cap,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        wp_enqueue_style('asp-admin', ASP_PLUGIN_URL . 'assets/admin/admin.css', [], ASP_VERSION);
        wp_enqueue_script('asp-stats', ASP_PLUGIN_URL . 'assets/admin/stats.js', [], ASP_VERSION, true);
        wp_localize_script(
            'asp-stats',
            'aspStats',
            [
                'endpoint' => esc_url_raw(rest_url('asp/v1/details')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'i18n'     => [
                    'loading' => __('Ładowanie…', 'audio-summary-player'),
                    'error'   => __('Nie udało się pobrać szczegółów.', 'audio-summary-player'),
                    'close'   => __('Zamknij', 'audio-summary-player'),
                    'event'   => __('Event', 'audio-summary-player'),
                    'when'    => __('Kiedy', 'audio-summary-player'),
                    'pos'     => __('Pozycja', 'audio-summary-player'),
                    'extra'   => __('Dane dodatkowe', 'audio-summary-player'),
                    'funnel'  => __('Lejek', 'audio-summary-player'),
                    'recent'  => __('Ostatnie eventy (top 50)', 'audio-summary-player'),
                    'bot'     => __('bot', 'audio-summary-player'),
                ],
                'labels'   => [
                    'started'    => __('Start', 'audio-summary-player'),
                    'reached_25' => __('25%', 'audio-summary-player'),
                    'reached_50' => __('50%', 'audio-summary-player'),
                    'reached_75' => __('75%', 'audio-summary-player'),
                    'completed'  => __('Complete', 'audio-summary-player'),
                ],
            ]
        );
    }

    public function renderPage(): void
    {
        $cap = (string) get_option('asp_stats_capability', 'manage_options');
        if (!current_user_can($cap)) {
            wp_die(esc_html__('Brak uprawnień.', 'audio-summary-player'), 403);
        }

        $range       = isset($_GET['range']) ? sanitize_key((string) wp_unslash($_GET['range'])) : '30';
        $customFrom  = isset($_GET['from']) ? sanitize_text_field((string) wp_unslash($_GET['from'])) : '';
        $includeBots = !empty($_GET['bots']);
        $sort        = isset($_GET['sort']) ? sanitize_key((string) wp_unslash($_GET['sort'])) : 'plays';
        $postFilter  = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $paged       = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);

        $sinceUtc = StatsRepository::rangeToSinceUtc($range, $customFrom !== '' ? $customFrom : null);

        $rows = $this->stats->funnelTable($sinceUtc, $includeBots);
        $rows = $this->annotateRows($rows, $sinceUtc, $includeBots, $postFilter);
        $rows = $this->sortRows($rows, $sort);

        $total = count($rows);
        $offset = ($paged - 1) * self::PER_PAGE;
        $pageRows = array_slice($rows, $offset, self::PER_PAGE);

        $daily = $this->stats->dailyPlays($sinceUtc, $includeBots);
        $speedDist = $this->stats->speedDistribution($sinceUtc, $includeBots);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Statystyki Audio Player', 'audio-summary-player'); ?></h1>

            <?php $this->renderFilters($range, $customFrom, $includeBots, $sort, $postFilter); ?>

            <div class="asp-stats__charts">
                <div class="asp-stats__chart">
                    <h2><?php esc_html_e('Odsłuchania w czasie', 'audio-summary-player'); ?></h2>
                    <?php echo $this->renderLineChart($daily, $sinceUtc); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes internally ?>
                </div>
                <div class="asp-stats__chart">
                    <h2><?php esc_html_e('Wykorzystanie speedu', 'audio-summary-player'); ?></h2>
                    <?php echo $this->renderPieChart($speedDist); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>

            <?php $this->renderExportLinks($range, $customFrom, $includeBots, $postFilter); ?>

            <?php if ($pageRows === []) : ?>
                <p class="asp-stats__empty"><?php esc_html_e('Brak danych dla wybranych filtrów.', 'audio-summary-player'); ?></p>
            <?php else : ?>
                <?php $this->renderTable($pageRows, $sinceUtc, $includeBots); ?>
                <?php $this->renderPagination($total, $paged, $range, $customFrom, $includeBots, $sort, $postFilter); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function annotateRows(array $rows, string $sinceUtc, bool $includeBots, int $postFilter): array
    {
        $out = [];
        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];
            if ($postFilter > 0 && $postId !== $postFilter) {
                continue;
            }
            $title = (string) get_the_title($postId);
            if ($title === '') {
                $title = sprintf('#%d', $postId);
            }
            $plays = (int) $row['started'];
            $completed = (int) $row['completed'];
            $completion = $plays > 0 ? round(($completed / $plays) * 100, 1) : 0.0;
            $avg = $this->stats->avgListenSecondsForPost($postId, $sinceUtc, $includeBots);
            $row['post_title'] = $title;
            $row['completion'] = $completion;
            $row['avg_listen'] = round($avg, 1);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function sortRows(array $rows, string $sort): array
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            switch ($sort) {
                case 'completion':
                    return ((float) $b['completion']) <=> ((float) $a['completion']);
                case 'avg_listen':
                    return ((float) $b['avg_listen']) <=> ((float) $a['avg_listen']);
                case 'date':
                    $da = (int) get_post_time('U', true, (int) $a['post_id']);
                    $db = (int) get_post_time('U', true, (int) $b['post_id']);
                    return $db <=> $da;
                case 'plays':
                default:
                    return ((int) $b['started']) <=> ((int) $a['started']);
            }
        });
        return $rows;
    }

    private function renderFilters(string $range, string $customFrom, bool $includeBots, string $sort, int $postFilter): void
    {
        ?>
        <form method="get" action="" class="asp-stats__filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <?php if ($postFilter > 0) : ?>
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postFilter); ?>" />
            <?php endif; ?>
            <label>
                <span><?php esc_html_e('Zakres', 'audio-summary-player'); ?></span>
                <select name="range">
                    <?php foreach (['7' => '7 dni', '30' => '30 dni', '90' => '90 dni', '180' => '180 dni', 'custom' => 'Niestandardowy'] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($range, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Od (custom)', 'audio-summary-player'); ?></span>
                <input type="date" name="from" value="<?php echo esc_attr($customFrom); ?>" />
            </label>
            <label>
                <span><?php esc_html_e('Sortowanie', 'audio-summary-player'); ?></span>
                <select name="sort">
                    <option value="plays" <?php selected($sort, 'plays'); ?>><?php esc_html_e('Plays', 'audio-summary-player'); ?></option>
                    <option value="completion" <?php selected($sort, 'completion'); ?>><?php esc_html_e('Completion', 'audio-summary-player'); ?></option>
                    <option value="avg_listen" <?php selected($sort, 'avg_listen'); ?>><?php esc_html_e('Avg listen', 'audio-summary-player'); ?></option>
                    <option value="date" <?php selected($sort, 'date'); ?>><?php esc_html_e('Data publikacji', 'audio-summary-player'); ?></option>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Pokaż boty', 'audio-summary-player'); ?></span>
                <input type="checkbox" name="bots" value="1" <?php checked($includeBots); ?> />
            </label>
            <?php submit_button(__('Filtruj', 'audio-summary-player'), 'primary', '', false); ?>
            <?php if ($postFilter > 0) : ?>
                <a class="button" href="<?php echo esc_url(remove_query_arg('post_id')); ?>">
                    <?php esc_html_e('Wyczyść filtr postu', 'audio-summary-player'); ?>
                </a>
            <?php endif; ?>
        </form>
        <?php
    }

    private function renderExportLinks(string $range, string $customFrom, bool $includeBots, int $postFilter): void
    {
        $base = admin_url('admin-post.php');
        $common = [
            'action' => CsvExporter::ACTION,
            'range'  => $range,
            'from'   => $customFrom,
            'bots'   => $includeBots ? '1' : '0',
        ];
        $eventsArgs = $common;
        $eventsArgs['scope'] = 'events';
        if ($postFilter > 0) {
            $eventsArgs['post_id'] = $postFilter;
        }
        $eventsUrl = wp_nonce_url(add_query_arg($eventsArgs, $base), CsvExporter::ACTION);

        $postsArgs = $common;
        $postsArgs['scope'] = 'posts';
        $postsUrl = wp_nonce_url(add_query_arg($postsArgs, $base), CsvExporter::ACTION);
        ?>
        <p>
            <a class="button" href="<?php echo esc_url($postsUrl); ?>"><?php esc_html_e('Eksport CSV (tabela)', 'audio-summary-player'); ?></a>
            <a class="button" href="<?php echo esc_url($eventsUrl); ?>"><?php esc_html_e('Eksport CSV (eventy)', 'audio-summary-player'); ?></a>
        </p>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function renderTable(array $rows, string $sinceUtc, bool $includeBots): void
    {
        ?>
        <table class="widefat striped asp-stats__table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Tytuł artykułu', 'audio-summary-player'); ?></th>
                    <th><?php esc_html_e('Plays', 'audio-summary-player'); ?></th>
                    <th><?php esc_html_e('Completion', 'audio-summary-player'); ?></th>
                    <th><?php esc_html_e('Avg Listen', 'audio-summary-player'); ?></th>
                    <th><?php esc_html_e('Lejek', 'audio-summary-player'); ?></th>
                    <th><?php esc_html_e('Akcje', 'audio-summary-player'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $postId = (int) $row['post_id'];
                    $editUrl = get_edit_post_link($postId);
                    $viewUrl = get_permalink($postId);
                    $started = max(1, (int) $row['started']);
                    $stages = [
                        (int) $row['started'],
                        (int) $row['reached_25'],
                        (int) $row['reached_50'],
                        (int) $row['reached_75'],
                        (int) $row['completed'],
                    ];
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html((string) $row['post_title']); ?></strong>
                            <?php if ($editUrl) : ?>
                                <div class="row-actions">
                                    <a href="<?php echo esc_url($editUrl); ?>"><?php esc_html_e('Edytuj', 'audio-summary-player'); ?></a>
                                    <?php if ($viewUrl) : ?>
                                        | <a href="<?php echo esc_url($viewUrl); ?>" target="_blank" rel="noopener"><?php esc_html_e('Zobacz', 'audio-summary-player'); ?></a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html((string) $row['started']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['completion'], 1)); ?>%</td>
                        <td><?php echo esc_html(PostMetabox::formatDuration((float) $row['avg_listen'])); ?></td>
                        <td>
                            <span class="asp-mini-funnel" aria-label="<?php esc_attr_e('Lejek', 'audio-summary-player'); ?>">
                                <?php foreach ($stages as $count) :
                                    $h = (int) round(($count / $started) * 24);
                                    $h = max(2, min(24, $h));
                                    ?>
                                    <span style="height: <?php echo esc_attr((string) $h); ?>px" title="<?php echo esc_attr((string) $count); ?>"></span>
                                <?php endforeach; ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="button-link asp-details-btn"
                                data-post-id="<?php echo esc_attr((string) $postId); ?>"
                                data-since="<?php echo esc_attr($sinceUtc); ?>"
                                data-bots="<?php echo $includeBots ? '1' : '0'; ?>">
                                <?php esc_html_e('Szczegóły', 'audio-summary-player'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderPagination(int $total, int $paged, string $range, string $customFrom, bool $includeBots, string $sort, int $postFilter): void
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($pages < 2) {
            return;
        }
        $args = [
            'page'  => self::PAGE_SLUG,
            'range' => $range,
            'from'  => $customFrom,
            'bots'  => $includeBots ? '1' : '',
            'sort'  => $sort,
        ];
        if ($postFilter > 0) {
            $args['post_id'] = $postFilter;
        }
        $base = add_query_arg($args, admin_url('admin.php')) . '%_%';

        $links = paginate_links([
            'base'      => $base,
            'format'    => '&paged=%#%',
            'current'   => $paged,
            'total'     => $pages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]);
        if (!empty($links)) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(is_array($links) ? implode(' ', $links) : $links) . '</div></div>';
        }
    }

    /**
     * @param array<string,int> $daily
     */
    private function renderLineChart(array $daily, string $sinceUtc): string
    {
        $start = strtotime($sinceUtc . ' UTC');
        if ($start === false) {
            return '';
        }
        $end = time();
        $points = [];
        for ($t = $start; $t <= $end; $t += DAY_IN_SECONDS) {
            $key = gmdate('Y-m-d', $t);
            $points[$key] = $daily[$key] ?? 0;
        }
        if ($points === []) {
            return '<p>' . esc_html__('Brak danych.', 'audio-summary-player') . '</p>';
        }

        $w = 600; $h = 200; $padX = 32; $padY = 24;
        $max = max($points);
        $max = $max > 0 ? $max : 1;
        $count = count($points);
        $stepX = $count > 1 ? ($w - 2 * $padX) / ($count - 1) : 0;

        $coords = [];
        $i = 0;
        $labels = array_keys($points);
        foreach ($points as $val) {
            $x = $padX + $stepX * $i;
            $y = $h - $padY - (($val / $max) * ($h - 2 * $padY));
            $coords[] = sprintf('%.1f,%.1f', $x, $y);
            $i++;
        }
        $polyline = implode(' ', $coords);

        $first = esc_html((string) $labels[0]);
        $last  = esc_html((string) $labels[$count - 1]);

        $svg  = '<svg class="asp-line" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__('Liczba odsłuchań w czasie', 'audio-summary-player') . '">';
        $svg .= '<line x1="' . $padX . '" y1="' . ($h - $padY) . '" x2="' . ($w - $padX) . '" y2="' . ($h - $padY) . '" stroke="#c3c4c7" />';
        $svg .= '<line x1="' . $padX . '" y1="' . $padY . '" x2="' . $padX . '" y2="' . ($h - $padY) . '" stroke="#c3c4c7" />';
        $svg .= '<polyline fill="none" stroke="#185fa5" stroke-width="2" points="' . esc_attr($polyline) . '" />';
        $svg .= '<text x="' . $padX . '" y="' . ($h - 4) . '" font-size="10" fill="#646970">' . $first . '</text>';
        $svg .= '<text x="' . ($w - $padX) . '" y="' . ($h - 4) . '" font-size="10" fill="#646970" text-anchor="end">' . $last . '</text>';
        $svg .= '<text x="' . ($padX - 4) . '" y="' . ($padY + 8) . '" font-size="10" fill="#646970" text-anchor="end">' . esc_html((string) $max) . '</text>';
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * @param array<string,int> $dist
     */
    private function renderPieChart(array $dist): string
    {
        if ($dist === []) {
            return '<p>' . esc_html__('Brak danych.', 'audio-summary-player') . '</p>';
        }
        $total = array_sum($dist);
        if ($total <= 0) {
            return '<p>' . esc_html__('Brak danych.', 'audio-summary-player') . '</p>';
        }
        $cx = 100; $cy = 100; $r = 90;
        $startAngle = -M_PI_2;
        $svg = '<svg class="asp-pie" viewBox="0 0 200 200" role="img" aria-label="' . esc_attr__('Wykorzystanie speedu', 'audio-summary-player') . '">';
        $legend = '<ul class="asp-pie-legend">';
        foreach ($dist as $key => $count) {
            $color = self::SPEED_COLORS[$key] ?? '#646970';
            $angle = ($count / $total) * 2 * M_PI;
            $endAngle = $startAngle + $angle;
            $x1 = $cx + $r * cos($startAngle);
            $y1 = $cy + $r * sin($startAngle);
            $x2 = $cx + $r * cos($endAngle);
            $y2 = $cy + $r * sin($endAngle);
            $largeArc = $angle > M_PI ? 1 : 0;
            if (count($dist) === 1) {
                $svg .= sprintf('<circle cx="%d" cy="%d" r="%d" fill="%s" />', $cx, $cy, $r, esc_attr($color));
            } else {
                $svg .= sprintf(
                    '<path d="M %d %d L %.2f %.2f A %d %d 0 %d 1 %.2f %.2f Z" fill="%s" />',
                    $cx, $cy, $x1, $y1, $r, $r, $largeArc, $x2, $y2, esc_attr($color)
                );
            }
            $startAngle = $endAngle;
            $pct = round(($count / $total) * 100, 1);
            $legend .= sprintf(
                '<li><i style="background:%s"></i> %sx — %s (%s%%)</li>',
                esc_attr($color),
                esc_html($key),
                esc_html((string) $count),
                esc_html((string) $pct)
            );
        }
        $svg .= '</svg>';
        $legend .= '</ul>';
        return $svg . $legend;
    }
}
