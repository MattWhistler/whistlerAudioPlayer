<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\REST;

use AudioSummaryPlayer\Database\EventRepository;
use AudioSummaryPlayer\Support\BotDetector;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /wp-json/asp/v1/event` — public endpoint for the player runtime.
 *
 * Security:
 *  - X-WP-Nonce verified by the `wp_rest` permission flow (rest_cookie_check_errors).
 *    We accept anonymous users — but only with a valid nonce — so spec sec. 8.5 holds.
 *  - Rate limiting: per session_id (30/min) AND per IP-hash (200/min).
 *  - Validator strips unknown fields, types coerced.
 *
 * Privacy:
 *  - IP is hashed (md5) and used only as a transient key with 60s TTL — never stored.
 *  - User-Agent used only by BotDetector at insert time, never stored.
 */
final class EventController
{
    public const NAMESPACE = 'asp/v1';
    public const ROUTE     = '/event';

    private const SESSION_LIMIT = 30;
    private const IP_LIMIT      = 200;
    private const WINDOW_SEC    = 60;

    private EventValidator $validator;
    private EventRepository $repository;

    public function __construct(?EventValidator $validator = null, ?EventRepository $repository = null)
    {
        $this->validator  = $validator ?? new EventValidator();
        $this->repository = $repository ?? new EventRepository();
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

    /**
     * Permission callback: nonce required (sent via `X-WP-Nonce` header
     * by `wp_localize_script`'s `aspConfig.nonce`). WordPress already verifies
     * the cookie/nonce flow upstream and exposes the result through
     * `wp_verify_nonce`. We re-check here because the endpoint accepts logged-out
     * traffic (`__return_true` would skip the nonce check).
     */
    public function permission(WP_REST_Request $request)
    {
        $nonce = $request->get_header('x_wp_nonce');
        if (!is_string($nonce) || $nonce === '') {
            // Fallback: sendBeacon can't set headers, so we accept query/body `_wpnonce`.
            $fromQuery = $request->get_param('_wpnonce');
            $nonce = is_string($fromQuery) ? $fromQuery : '';
        }
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
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

        $validated = $this->validator->validate($payload);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        $rateError = $this->checkRateLimit($validated['session_id']);
        if ($rateError instanceof WP_Error) {
            return $rateError;
        }

        $userAgent = $request->get_header('user_agent');
        $isBot     = BotDetector::isBot(is_string($userAgent) ? $userAgent : null);

        $ok = $this->repository->insert([
            'post_id'    => $validated['post_id'],
            'session_id' => $validated['session_id'],
            'event_type' => $validated['event_type'],
            'position'   => $validated['position'],
            'duration'   => $validated['duration'],
            'speed'      => $validated['speed'],
            'extra'      => $validated['extra'],
            'is_bot'     => $isBot,
        ]);

        if (!$ok) {
            return new WP_Error('insert_failed', 'Could not store event', ['status' => 500]);
        }

        return new WP_REST_Response(['success' => true], 201);
    }

    /**
     * @return true|WP_Error
     */
    private function checkRateLimit(string $sessionId)
    {
        $sessionKey = 'asp_rate_' . md5($sessionId);
        $count      = (int) get_transient($sessionKey);
        if ($count >= self::SESSION_LIMIT) {
            return self::rateLimited();
        }
        set_transient($sessionKey, $count + 1, self::WINDOW_SEC);

        $ip = $this->clientIp();
        if ($ip !== '') {
            $ipKey   = 'asp_rate_ip_' . md5($ip);
            $ipCount = (int) get_transient($ipKey);
            if ($ipCount >= self::IP_LIMIT) {
                return self::rateLimited();
            }
            set_transient($ipKey, $ipCount + 1, self::WINDOW_SEC);
        }
        return true;
    }

    private static function rateLimited(): WP_Error
    {
        return new WP_Error(
            'rate_limited',
            'Too many requests',
            ['status' => 429, 'retry_after' => self::WINDOW_SEC]
        );
    }

    /**
     * Best-effort client IP for rate limiting only — never persisted.
     * Honors common proxy headers but keeps it conservative (first hop).
     */
    private function clientIp(): string
    {
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $raw = (string) wp_unslash($_SERVER[$key]);
            $first = trim(explode(',', $raw)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return '';
    }
}
