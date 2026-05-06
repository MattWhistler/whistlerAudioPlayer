<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Database;

/**
 * Inserts events into the wp_asp_events table.
 *
 * Aggregation queries belong to Stage 3 (admin); this class is intentionally
 * minimal in Stage 2 — it only owns the write path.
 */
final class EventRepository
{
    /**
     * @param array{
     *   post_id:int,
     *   session_id:string,
     *   event_type:string,
     *   position?:float|null,
     *   duration?:float|null,
     *   speed?:float|null,
     *   extra?:array<string,mixed>|null,
     *   is_bot?:bool
     * } $event
     */
    public function insert(array $event): bool
    {
        global $wpdb;

        if (!Schema::tableExists()) {
            // Table missing (e.g. partial activation). Try once to recreate.
            Schema::migrate();
            if (!Schema::tableExists()) {
                error_log('[ASP] events table missing, insert skipped');
                return false;
            }
        }

        $extra = $event['extra'] ?? null;
        $extraJson = null;
        if (is_array($extra) && $extra !== []) {
            $encoded = wp_json_encode($extra);
            if (is_string($encoded)) {
                $extraJson = $encoded;
            }
        }

        $data = [
            'post_id'          => absint($event['post_id']),
            'session_id'       => (string) $event['session_id'],
            'event_type'       => sanitize_key($event['event_type']),
            'position_seconds' => isset($event['position']) ? (float) $event['position'] : null,
            'duration_seconds' => isset($event['duration']) ? (float) $event['duration'] : null,
            'speed'            => isset($event['speed']) ? (float) $event['speed'] : 1.0,
            'extra_data'       => $extraJson,
            'is_bot'           => !empty($event['is_bot']) ? 1 : 0,
            'created_at'       => current_time('mysql', true),
        ];

        $formats = ['%d', '%s', '%s', '%f', '%f', '%f', '%s', '%d', '%s'];

        $result = $wpdb->insert(Schema::tableName(), $data, $formats);

        if ($result === false) {
            error_log('[ASP] insert failed: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }
}
