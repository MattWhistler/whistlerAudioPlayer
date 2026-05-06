<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Admin;

use AudioSummaryPlayer\Database\StatsRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * "Statystyki audio" metabox shown on the post edit screen.
 *
 * Render is a thin shell — actual numbers are fetched async by `metabox.js`
 * via `GET /asp/v1/metabox?post_id=…` so the editor is not blocked. Results
 * are cached for 60s in a transient (spec sec. 12.1).
 *
 * Capability: `edit_posts` (spec sec. 12.4).
 */
final class PostMetabox
{
    private const META_BOX_ID  = 'asp_stats_metabox';
    private const NONCE_ACTION = 'asp_metabox';
    private const CACHE_TTL    = 60;

    private StatsRepository $stats;

    public function __construct(?StatsRepository $stats = null)
    {
        $this->stats = $stats ?? new StatsRepository();
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function addMetaBox(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $postType = $screen instanceof \WP_Screen ? $screen->post_type : 'post';

        add_meta_box(
            self::META_BOX_ID,
            __('Statystyki Audio Player', 'audio-summary-player'),
            [$this, 'renderMetaBox'],
            $postType,
            'side',
            'default'
        );
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }
        $statsUrl = add_query_arg(
            ['page' => StatsPage::PAGE_SLUG, 'post_id' => $post->ID],
            admin_url('admin.php')
        );
        ?>
        <div class="asp-metabox" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <p class="asp-metabox__loading"><?php esc_html_e('Ładowanie statystyk…', 'audio-summary-player'); ?></p>
            <div class="asp-metabox__content" hidden>
                <ul class="asp-metabox__kpis">
                    <li><strong data-asp="plays">—</strong> <span><?php esc_html_e('Total Plays', 'audio-summary-player'); ?></span></li>
                    <li><strong data-asp="completion">—</strong> <span><?php esc_html_e('Completion Rate', 'audio-summary-player'); ?></span></li>
                    <li><strong data-asp="avg-listen">—</strong> <span><?php esc_html_e('Avg Listen Time', 'audio-summary-player'); ?></span></li>
                </ul>
                <h4 class="asp-metabox__heading"><?php esc_html_e('Lejek (ostatnie 30 dni)', 'audio-summary-player'); ?></h4>
                <ol class="asp-metabox__funnel" data-asp="funnel"></ol>
                <p class="asp-metabox__link">
                    <a href="<?php echo esc_url($statsUrl); ?>"><?php esc_html_e('Pełne statystyki →', 'audio-summary-player'); ?></a>
                </p>
            </div>
            <p class="asp-metabox__error" hidden></p>
        </div>
        <?php
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        $src = ASP_PLUGIN_URL . 'assets/admin/metabox.js';
        wp_enqueue_script('asp-metabox', $src, [], ASP_VERSION, true);
        wp_localize_script(
            'asp-metabox',
            'aspMetabox',
            [
                'endpoint' => esc_url_raw(rest_url('asp/v1/metabox')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'i18n'     => [
                    'error'   => __('Nie udało się pobrać statystyk.', 'audio-summary-player'),
                    'percent' => __('%s%%', 'audio-summary-player'),
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

        $css = ASP_PLUGIN_URL . 'assets/admin/admin.css';
        wp_enqueue_style('asp-admin', $css, [], ASP_VERSION);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'asp/v1',
            '/metabox',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'restCallback'],
                'permission_callback' => [$this, 'restPermission'],
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
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
        $postId = (int) $request->get_param('post_id');
        return $postId > 0 && current_user_can('edit_post', $postId);
    }

    /**
     * @return WP_REST_Response
     */
    public function restCallback(WP_REST_Request $request)
    {
        $postId = (int) $request->get_param('post_id');
        $data = $this->buildPayload($postId);
        return new WP_REST_Response($data, 200);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(int $postId): array
    {
        $cacheKey = 'asp_metabox_' . $postId;
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sinceUtc = StatsRepository::rangeToSinceUtc('30');
        $funnel = $this->stats->funnelForPost($postId, $sinceUtc, false);
        $avg    = $this->stats->avgListenSecondsForPost($postId, $sinceUtc, false);
        $plays  = (int) $funnel['started'];
        $complete = (int) $funnel['completed'];
        $completionRate = $plays > 0 ? round(($complete / $plays) * 100, 1) : 0.0;

        $payload = [
            'post_id'         => $postId,
            'plays'           => $plays,
            'completion_rate' => $completionRate,
            'avg_listen'      => round($avg, 1),
            'avg_listen_human' => self::formatDuration($avg),
            'funnel'          => [
                'started'    => (int) $funnel['started'],
                'reached_25' => (int) $funnel['reached_25'],
                'reached_50' => (int) $funnel['reached_50'],
                'reached_75' => (int) $funnel['reached_75'],
                'completed'  => (int) $funnel['completed'],
            ],
        ];

        set_transient($cacheKey, $payload, self::CACHE_TTL);
        return $payload;
    }

    public static function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $m = (int) floor($seconds / 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d', $m, $s);
    }
}
