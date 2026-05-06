<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Database;

/**
 * Stores per-(post,session) reactions and exposes count aggregations.
 *
 * Each session can hold at most one reaction per post (PK on post_id+session_id).
 * Setting the same reaction twice toggles it off (delete row), switching toggles
 * the reaction column. The frontend mirrors that state via localStorage to give
 * an immediate UI response, but the server always re-derives counts from this
 * table — never trusts the client.
 */
final class ReactionRepository
{
    public const LIKE    = 1;
    public const DISLIKE = -1;

    /**
     * Apply a reaction. If `$reaction === 0`, removes the row.
     * Returns the resulting reaction stored for that (post, session): 1, -1, or 0.
     */
    public function set(int $postId, string $sessionId, int $reaction): int
    {
        global $wpdb;

        if (!Schema::reactionsTableExists()) {
            Schema::migrate();
            if (!Schema::reactionsTableExists()) {
                error_log('[ASP] reactions table missing, set skipped');
                return 0;
            }
        }

        $table = Schema::reactionsTableName();
        $reaction = $this->normalize($reaction);

        if ($reaction === 0) {
            $wpdb->delete(
                $table,
                ['post_id' => $postId, 'session_id' => $sessionId],
                ['%d', '%s']
            );
            return 0;
        }

        $now = current_time('mysql', true);
        // INSERT … ON DUPLICATE KEY UPDATE keeps it a single round-trip.
        $sql = "INSERT INTO {$table} (post_id, session_id, reaction, created_at, updated_at)
                VALUES (%d, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), updated_at = VALUES(updated_at)";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is static.
        $wpdb->query($wpdb->prepare($sql, $postId, $sessionId, $reaction, $now, $now));

        return $reaction;
    }

    /**
     * @return array{likes:int,dislikes:int}
     */
    public function counts(int $postId): array
    {
        global $wpdb;

        if (!Schema::reactionsTableExists()) {
            return ['likes' => 0, 'dislikes' => 0];
        }

        $table = Schema::reactionsTableName();
        $sql = "SELECT
                    SUM(CASE WHEN reaction = 1 THEN 1 ELSE 0 END) AS likes,
                    SUM(CASE WHEN reaction = -1 THEN 1 ELSE 0 END) AS dislikes
                FROM {$table}
                WHERE post_id = %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is static.
        $row = $wpdb->get_row($wpdb->prepare($sql, $postId), ARRAY_A);

        return [
            'likes'    => isset($row['likes']) ? (int) $row['likes'] : 0,
            'dislikes' => isset($row['dislikes']) ? (int) $row['dislikes'] : 0,
        ];
    }

    public function reactionFor(int $postId, string $sessionId): int
    {
        global $wpdb;

        if (!Schema::reactionsTableExists()) {
            return 0;
        }

        $table = Schema::reactionsTableName();
        $sql = "SELECT reaction FROM {$table} WHERE post_id = %d AND session_id = %s LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is static.
        $value = $wpdb->get_var($wpdb->prepare($sql, $postId, $sessionId));
        return $value === null ? 0 : $this->normalize((int) $value);
    }

    private function normalize(int $reaction): int
    {
        if ($reaction > 0) {
            return self::LIKE;
        }
        if ($reaction < 0) {
            return self::DISLIKE;
        }
        return 0;
    }
}
