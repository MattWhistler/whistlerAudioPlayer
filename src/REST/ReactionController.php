<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\REST;

use AudioSummaryPlayer\Database\ReactionRepository;
use AudioSummaryPlayer\Support\SessionId;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /wp-json/asp/v1/reaction` — record a 👍 / 👎 toggle for a post.
 *
 * Body: { post_id, session_id (uuid v4), reaction: "like" | "dislike" | "none" }.
 * Same nonce flow as EventController. Each (post_id, session_id) holds at most
 * one reaction; sending the same value again clears it (toggle off).
 */
final class ReactionController
{
    public const NAMESPACE = 'asp/v1';
    public const ROUTE     = '/reaction';

    private const VALID_REACTIONS = ['like', 'dislike', 'none'];

    private ReactionRepository $repository;

    public function __construct(?ReactionRepository $repository = null)
    {
        $this->repository = $repository ?? new ReactionRepository();
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
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle'],
                'permission_callback' => [$this, 'permission'],
            ]
        );
    }

    public function permission(WP_REST_Request $request)
    {
        $nonce = $request->get_header('x_wp_nonce');
        if (!is_string($nonce) || $nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('rest_forbidden', 'Invalid or missing nonce', ['status' => 403]);
        }
        return true;
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handle(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('invalid_payload', 'Body must be JSON object', ['status' => 400]);
        }

        $postId = isset($payload['post_id']) ? absint($payload['post_id']) : 0;
        if ($postId <= 0 || get_post_status($postId) !== 'publish') {
            return new WP_Error('invalid_payload', 'post_id must reference a published post', ['status' => 400]);
        }

        $sessionId = isset($payload['session_id']) ? (string) $payload['session_id'] : '';
        if (!SessionId::isValid($sessionId)) {
            return new WP_Error('invalid_payload', 'session_id must be a valid UUID v4', ['status' => 400]);
        }

        $reaction = isset($payload['reaction']) ? sanitize_key((string) $payload['reaction']) : '';
        if (!in_array($reaction, self::VALID_REACTIONS, true)) {
            return new WP_Error('invalid_payload', 'reaction must be like|dislike|none', ['status' => 400]);
        }

        $value = 0;
        if ($reaction === 'like') {
            $value = ReactionRepository::LIKE;
        } elseif ($reaction === 'dislike') {
            $value = ReactionRepository::DISLIKE;
        }

        $stored = $this->repository->set($postId, strtolower($sessionId), $value);
        $counts = $this->repository->counts($postId);

        return new WP_REST_Response([
            'success'  => true,
            'reaction' => $this->labelFor($stored),
            'likes'    => $counts['likes'],
            'dislikes' => $counts['dislikes'],
        ], 200);
    }

    private function labelFor(int $value): string
    {
        if ($value === ReactionRepository::LIKE) {
            return 'like';
        }
        if ($value === ReactionRepository::DISLIKE) {
            return 'dislike';
        }
        return 'none';
    }
}
