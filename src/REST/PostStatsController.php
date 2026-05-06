<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\REST;

use AudioSummaryPlayer\Database\ReactionRepository;
use AudioSummaryPlayer\Database\StatsRepository;
use AudioSummaryPlayer\Support\SessionId;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /wp-json/asp/v1/post-stats?post_id=…[&session_id=…]` — public counters
 * the player block displays under the audio control: total plays + reactions.
 *
 * Read-only, no nonce required (renders on cached frontends; data is the same
 * we already expose visually). When `session_id` is provided, the response
 * also tells the client which reaction (if any) belongs to that session — so
 * the UI can render its toggled state on first paint.
 */
final class PostStatsController
{
    public const NAMESPACE = 'asp/v1';
    public const ROUTE     = '/post-stats';

    private StatsRepository $stats;
    private ReactionRepository $reactions;

    public function __construct(?StatsRepository $stats = null, ?ReactionRepository $reactions = null)
    {
        $this->stats     = $stats ?? new StatsRepository();
        $this->reactions = $reactions ?? new ReactionRepository();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'session_id' => [
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handle(WP_REST_Request $request)
    {
        $postId = (int) $request->get_param('post_id');
        if ($postId <= 0 || get_post_status($postId) !== 'publish') {
            return new WP_Error('invalid_post', 'post_id must reference a published post', ['status' => 400]);
        }

        $plays = $this->stats->totalPlaysForPost($postId);
        $counts = $this->reactions->counts($postId);

        $userReaction = 'none';
        $sessionId = (string) $request->get_param('session_id');
        if ($sessionId !== '' && SessionId::isValid($sessionId)) {
            $value = $this->reactions->reactionFor($postId, strtolower($sessionId));
            if ($value === ReactionRepository::LIKE) {
                $userReaction = 'like';
            } elseif ($value === ReactionRepository::DISLIKE) {
                $userReaction = 'dislike';
            }
        }

        return new WP_REST_Response([
            'post_id'       => $postId,
            'plays'         => $plays,
            'likes'         => $counts['likes'],
            'dislikes'      => $counts['dislikes'],
            'user_reaction' => $userReaction,
        ], 200);
    }
}
