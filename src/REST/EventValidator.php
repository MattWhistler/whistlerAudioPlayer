<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\REST;

use AudioSummaryPlayer\Support\SessionId;
use WP_Error;

/**
 * Validates incoming REST payloads. Returns either a normalized event array
 * (typed, sanitized) or a WP_Error with `invalid_payload` code + 400 status.
 *
 * Strict whitelist — unknown fields are dropped so we never insert garbage.
 */
final class EventValidator
{
    public const EVENT_TYPES = [
        'play_intent',
        'pause',
        'resume',
        'checkpoint_25',
        'checkpoint_50',
        'checkpoint_75',
        'complete',
        'abandon',
        'seek',
        'speed_change',
    ];

    private const ALLOWED_EXTRA_KEYS = [
        'from_position',
        'to_position',
        'new_speed',
        'total_listened_seconds',
    ];

    /**
     * @param array<string,mixed> $payload Raw decoded payload.
     * @return array<string,mixed>|WP_Error
     */
    public function validate(array $payload)
    {
        $postId = isset($payload['post_id']) ? absint($payload['post_id']) : 0;
        if ($postId <= 0 || get_post_status($postId) !== 'publish') {
            return self::error('post_id must reference a published post');
        }

        $sessionId = isset($payload['session_id']) ? (string) $payload['session_id'] : '';
        if (!SessionId::isValid($sessionId)) {
            return self::error('session_id must be a valid UUID v4');
        }

        $eventType = isset($payload['event_type']) ? sanitize_key((string) $payload['event_type']) : '';
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            return self::error('event_type is not in the allowed list');
        }

        $duration = isset($payload['duration']) ? (float) $payload['duration'] : 0.0;
        if ($duration <= 0.0) {
            return self::error('duration must be greater than 0');
        }

        $position = isset($payload['position']) ? (float) $payload['position'] : 0.0;
        // Allow tiny float overrun from client-side imprecision (e.g. 154.01 vs 154.00).
        if ($position < 0.0 || $position > $duration + 1.0) {
            return self::error('position out of range');
        }
        if ($position > $duration) {
            $position = $duration;
        }

        $speed = 1.0;
        if (isset($payload['speed'])) {
            $speed = (float) $payload['speed'];
            if ($speed < 0.5 || $speed > 3.0) {
                return self::error('speed out of allowed range (0.5–3.0)');
            }
        }

        $extra = [];
        if (isset($payload['extra']) && is_array($payload['extra'])) {
            foreach ($payload['extra'] as $key => $value) {
                $key = sanitize_key((string) $key);
                if (!in_array($key, self::ALLOWED_EXTRA_KEYS, true)) {
                    continue;
                }
                if (is_numeric($value)) {
                    $extra[$key] = (float) $value;
                }
            }
        }

        return [
            'post_id'    => $postId,
            'session_id' => strtolower($sessionId),
            'event_type' => $eventType,
            'position'   => round($position, 2),
            'duration'   => round($duration, 2),
            'speed'      => round($speed, 2),
            'extra'      => $extra,
        ];
    }

    private static function error(string $message): WP_Error
    {
        return new WP_Error('invalid_payload', $message, ['status' => 400]);
    }
}
