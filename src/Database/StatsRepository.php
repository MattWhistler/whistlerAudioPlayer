<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Database;

/**
 * Aggregation queries used by the admin UI (metabox + stats page).
 *
 * All queries default to `is_bot = 0`. Pass `$includeBots = true` to the
 * relevant methods to bypass that filter (used by the bots toggle in the
 * stats page and the raw CSV export).
 *
 * Spec sec. 12.3 lists the canonical SQL — implementations here mirror it
 * but are parameterised through `$wpdb->prepare()` and never concatenate
 * user input.
 */
final class StatsRepository
{
    private const FUNNEL_EVENTS = ['play_intent', 'checkpoint_25', 'checkpoint_50', 'checkpoint_75', 'complete'];

    public function tableName(): string
    {
        return Schema::tableName();
    }

    /**
     * Funnel for a single post in a date range. Counts unique sessions per stage.
     *
     * @return array{started:int,reached_25:int,reached_50:int,reached_75:int,completed:int}
     */
    public function funnelForPost(int $postId, string $sinceUtc, bool $includeBots = false): array
    {
        global $wpdb;
        $table = $this->tableName();

        $botClause = $includeBots ? '' : ' AND is_bot = 0';

        $sql = "SELECT
                    COUNT(DISTINCT CASE WHEN event_type = 'play_intent' THEN session_id END) AS started,
                    COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_25' THEN session_id END) AS reached_25,
                    COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_50' THEN session_id END) AS reached_50,
                    COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_75' THEN session_id END) AS reached_75,
                    COUNT(DISTINCT CASE WHEN event_type = 'complete' THEN session_id END) AS completed
                FROM {$table}
                WHERE post_id = %d{$botClause}
                  AND created_at >= %s";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $botClause are static.
        $row = $wpdb->get_row($wpdb->prepare($sql, $postId, $sinceUtc), ARRAY_A);
        if (!is_array($row)) {
            return ['started' => 0, 'reached_25' => 0, 'reached_50' => 0, 'reached_75' => 0, 'completed' => 0];
        }
        return [
            'started'    => (int) $row['started'],
            'reached_25' => (int) $row['reached_25'],
            'reached_50' => (int) $row['reached_50'],
            'reached_75' => (int) $row['reached_75'],
            'completed'  => (int) $row['completed'],
        ];
    }

    /**
     * Average `total_listened_seconds` from `complete` and `abandon` events.
     */
    public function avgListenSecondsForPost(int $postId, string $sinceUtc, bool $includeBots = false): float
    {
        global $wpdb;
        $table = $this->tableName();
        $botClause = $includeBots ? '' : ' AND is_bot = 0';

        $sql = "SELECT AVG(CAST(JSON_EXTRACT(extra_data, '$.total_listened_seconds') AS DECIMAL(10,2))) AS avg_listen
                FROM {$table}
                WHERE post_id = %d
                  AND event_type IN ('complete', 'abandon')
                  AND extra_data IS NOT NULL
                  {$botClause}
                  AND created_at >= %s";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $wpdb->get_var($wpdb->prepare($sql, $postId, $sinceUtc));
        return $value === null ? 0.0 : (float) $value;
    }

    /**
     * Posts that have at least one event in the date range, with stage counts.
     *
     * @return array<int,array{post_id:int,started:int,reached_25:int,reached_50:int,reached_75:int,completed:int}>
     */
    public function funnelTable(string $sinceUtc, bool $includeBots = false): array
    {
        global $wpdb;
        $table = $this->tableName();
        $botClause = $includeBots ? '' : ' AND is_bot = 0';

        $sql = "SELECT
                    post_id,
                    COUNT(DISTINCT CASE WHEN event_type = 'play_intent' THEN session_id END) AS started,
                    COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_25' THEN session_id END) AS reached_25,
                    COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_50' THEN session_id END) AS reached_50,
                    COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_75' THEN session_id END) AS reached_75,
                    COUNT(DISTINCT CASE WHEN event_type = 'complete' THEN session_id END) AS completed
                FROM {$table}
                WHERE created_at >= %s{$botClause}
                GROUP BY post_id
                HAVING started > 0";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $sinceUtc), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'post_id'    => (int) $row['post_id'],
                'started'    => (int) $row['started'],
                'reached_25' => (int) $row['reached_25'],
                'reached_50' => (int) $row['reached_50'],
                'reached_75' => (int) $row['reached_75'],
                'completed'  => (int) $row['completed'],
            ];
        }
        return $out;
    }

    /**
     * Per-day count of unique starts over the date range.
     *
     * @return array<string,int> map of `Y-m-d` UTC → count
     */
    public function dailyPlays(string $sinceUtc, bool $includeBots = false): array
    {
        global $wpdb;
        $table = $this->tableName();
        $botClause = $includeBots ? '' : ' AND is_bot = 0';

        $sql = "SELECT DATE(created_at) AS day, COUNT(DISTINCT session_id) AS plays
                FROM {$table}
                WHERE event_type = 'play_intent'{$botClause}
                  AND created_at >= %s
                GROUP BY DATE(created_at)
                ORDER BY day ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $sinceUtc), ARRAY_A);
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[(string) $row['day']] = (int) $row['plays'];
            }
        }
        return $out;
    }

    /**
     * Distribution of speed values selected by users.
     *
     * @return array<string,int> "1.00" → count
     */
    public function speedDistribution(string $sinceUtc, bool $includeBots = false): array
    {
        global $wpdb;
        $table = $this->tableName();
        $botClause = $includeBots ? '' : ' AND is_bot = 0';

        $sql = "SELECT speed, COUNT(*) AS cnt
                FROM {$table}
                WHERE event_type = 'speed_change'{$botClause}
                  AND created_at >= %s
                GROUP BY speed
                ORDER BY speed ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $sinceUtc), ARRAY_A);
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $key = number_format((float) $row['speed'], 2, '.', '');
                $out[$key] = (int) $row['cnt'];
            }
        }
        return $out;
    }

    /**
     * Latest events for a post (used in the details modal).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentEventsForPost(int $postId, int $limit = 50, bool $includeBots = false): array
    {
        global $wpdb;
        $table = $this->tableName();
        $botClause = $includeBots ? '' : ' AND is_bot = 0';
        $limit = max(1, min(500, $limit));

        $sql = "SELECT id, event_type, position_seconds, duration_seconds, speed, extra_data, is_bot, created_at
                FROM {$table}
                WHERE post_id = %d{$botClause}
                ORDER BY created_at DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $postId, $limit), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Delete events older than the given UTC datetime. Returns number of rows deleted.
     */
    public function deleteOlderThan(string $beforeUtc): int
    {
        global $wpdb;
        $table = $this->tableName();
        $sql = "DELETE FROM {$table} WHERE created_at < %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query($wpdb->prepare($sql, $beforeUtc));
        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Stream events to a callable in chunks (used by CSV export). The callable
     * receives one assoc-array row at a time.
     *
     * @param callable(array<string,mixed>):void $sink
     */
    public function streamEvents(string $sinceUtc, ?int $postId, bool $includeBots, callable $sink, int $chunk = 500): void
    {
        global $wpdb;
        $table = $this->tableName();
        $botClause = $includeBots ? '' : ' AND is_bot = 0';
        $postClause = $postId !== null ? ' AND post_id = %d' : '';
        $chunk = max(50, min(5000, $chunk));

        $offset = 0;
        do {
            if ($postId !== null) {
                $sql = "SELECT id, post_id, session_id, event_type, position_seconds, duration_seconds,
                               speed, extra_data, is_bot, created_at
                        FROM {$table}
                        WHERE created_at >= %s{$botClause}{$postClause}
                        ORDER BY id ASC
                        LIMIT %d OFFSET %d";
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results($wpdb->prepare($sql, $sinceUtc, $postId, $chunk, $offset), ARRAY_A);
            } else {
                $sql = "SELECT id, post_id, session_id, event_type, position_seconds, duration_seconds,
                               speed, extra_data, is_bot, created_at
                        FROM {$table}
                        WHERE created_at >= %s{$botClause}
                        ORDER BY id ASC
                        LIMIT %d OFFSET %d";
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results($wpdb->prepare($sql, $sinceUtc, $chunk, $offset), ARRAY_A);
            }
            if (!is_array($rows) || $rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $sink($row);
            }
            $offset += $chunk;
        } while (count($rows) === $chunk);
    }

    /**
     * Resolve a date-range preset (`7`, `30`, `90`, `180`, or `custom`) into a UTC `Y-m-d H:i:s` string.
     */
    public static function rangeToSinceUtc(string $preset, ?string $customFrom = null): string
    {
        $valid = ['7', '30', '90', '180'];
        if (in_array($preset, $valid, true)) {
            $days = (int) $preset;
            return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        }
        if ($preset === 'custom' && is_string($customFrom) && $customFrom !== '') {
            $ts = strtotime($customFrom . ' 00:00:00 UTC');
            if ($ts !== false) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
        }
        return gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    }
}
