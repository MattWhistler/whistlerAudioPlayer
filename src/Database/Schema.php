<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Database;

/**
 * Owns the database schema for the events table.
 *
 * Migrations run via `dbDelta()` on plugin activation and on version bumps
 * detected during boot. Schema version stored in option `asp_db_version`.
 */
final class Schema
{
    public const SCHEMA_VERSION = '1.1.0';
    public const TABLE          = 'asp_events';
    public const REACTIONS_TABLE = 'asp_reactions';
    private const OPTION_VERSION = 'asp_db_version';

    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function reactionsTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::REACTIONS_TABLE;
    }

    /**
     * Create / migrate the events table. Idempotent — safe to call multiple times.
     */
    public static function migrate(): void
    {
        global $wpdb;

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            session_id CHAR(36) NOT NULL,
            event_type VARCHAR(20) NOT NULL,
            position_seconds DECIMAL(8,2) DEFAULT NULL,
            duration_seconds DECIMAL(8,2) DEFAULT NULL,
            speed DECIMAL(3,2) DEFAULT 1.00,
            extra_data LONGTEXT DEFAULT NULL,
            is_bot TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_post_event (post_id, event_type),
            KEY idx_post_session (post_id, session_id),
            KEY idx_created (created_at)
        ) {$charset};";

        $reactionsTable = self::reactionsTableName();
        $reactionsSql = "CREATE TABLE {$reactionsTable} (
            post_id BIGINT UNSIGNED NOT NULL,
            session_id CHAR(36) NOT NULL,
            reaction TINYINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (post_id, session_id),
            KEY idx_post_reaction (post_id, reaction)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($reactionsSql);

        update_option(self::OPTION_VERSION, self::SCHEMA_VERSION, false);
    }

    /**
     * Run migration when stored version differs from current — called on boot.
     */
    public static function maybeUpgrade(): void
    {
        $stored = (string) get_option(self::OPTION_VERSION, '');
        if ($stored !== self::SCHEMA_VERSION) {
            self::migrate();
        }
    }

    /**
     * Best-effort table existence check used by EventRepository.
     */
    public static function tableExists(): bool
    {
        global $wpdb;
        $table = self::tableName();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }

    public static function reactionsTableExists(): bool
    {
        global $wpdb;
        $table = self::reactionsTableName();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }
}
