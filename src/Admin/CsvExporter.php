<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Admin;

use AudioSummaryPlayer\Database\StatsRepository;

/**
 * Streams CSV exports through `php://output`.
 *
 * Triggered from the stats page via `admin-post.php?action=asp_export_csv`.
 * Two scopes:
 *  - `events` — every row in the date range (one event per line)
 *  - `posts` — aggregated table per post (mirrors the stats screen)
 *
 * UTF-8 BOM emitted so Excel auto-detects encoding (spec sec. 12 export note).
 */
final class CsvExporter
{
    public const ACTION = 'asp_export_csv';

    private StatsRepository $stats;

    public function __construct(?StatsRepository $stats = null)
    {
        $this->stats = $stats ?? new StatsRepository();
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        $cap = (string) get_option('asp_stats_capability', 'manage_options');
        if (!current_user_can($cap)) {
            wp_die(esc_html__('Brak uprawnień.', 'audio-summary-player'), 403);
        }
        check_admin_referer(self::ACTION);

        $scope        = isset($_GET['scope']) ? sanitize_key((string) wp_unslash($_GET['scope'])) : 'events';
        $rangePreset  = isset($_GET['range']) ? sanitize_key((string) wp_unslash($_GET['range'])) : '30';
        $customFrom   = isset($_GET['from']) ? sanitize_text_field((string) wp_unslash($_GET['from'])) : null;
        $includeBots  = !empty($_GET['bots']);
        $postId       = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        $sinceUtc = StatsRepository::rangeToSinceUtc($rangePreset, $customFrom);

        $filename = sprintf('asp-%s-%s.csv', $scope, gmdate('Ymd-His'));
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // BOM so Excel detects UTF-8 correctly.
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        if ($out === false) {
            wp_die(esc_html__('Nie można otworzyć strumienia wyjścia.', 'audio-summary-player'), 500);
        }

        if ($scope === 'posts') {
            $this->writePostsCsv($out, $sinceUtc, $includeBots);
        } else {
            $this->writeEventsCsv($out, $sinceUtc, $includeBots, $postId > 0 ? $postId : null);
        }

        fclose($out);
        exit;
    }

    /**
     * @param resource $out
     */
    private function writeEventsCsv($out, string $sinceUtc, bool $includeBots, ?int $postId): void
    {
        fputcsv($out, [
            'id', 'post_id', 'post_title', 'session_id', 'event_type',
            'position_seconds', 'duration_seconds', 'speed', 'extra_data',
            'is_bot', 'created_at',
        ]);

        $titleCache = [];
        $resolveTitle = static function (int $id) use (&$titleCache): string {
            if (!isset($titleCache[$id])) {
                $titleCache[$id] = (string) get_the_title($id);
            }
            return $titleCache[$id];
        };

        $this->stats->streamEvents($sinceUtc, $postId, $includeBots, function (array $row) use ($out, $resolveTitle): void {
            fputcsv($out, [
                $row['id'],
                $row['post_id'],
                $resolveTitle((int) $row['post_id']),
                $row['session_id'],
                $row['event_type'],
                $row['position_seconds'],
                $row['duration_seconds'],
                $row['speed'],
                $row['extra_data'],
                $row['is_bot'],
                $row['created_at'],
            ]);
        });
    }

    /**
     * @param resource $out
     */
    private function writePostsCsv($out, string $sinceUtc, bool $includeBots): void
    {
        fputcsv($out, [
            'post_id', 'post_title', 'plays', 'completion_rate_percent',
            'avg_listen_seconds', 'reached_25', 'reached_50', 'reached_75', 'completed',
        ]);

        $rows = $this->stats->funnelTable($sinceUtc, $includeBots);
        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];
            $plays  = (int) $row['started'];
            $completed = (int) $row['completed'];
            $completion = $plays > 0 ? round(($completed / $plays) * 100, 1) : 0.0;
            $avg = $this->stats->avgListenSecondsForPost($postId, $sinceUtc, $includeBots);
            fputcsv($out, [
                $postId,
                (string) get_the_title($postId),
                $plays,
                $completion,
                round($avg, 1),
                $row['reached_25'],
                $row['reached_50'],
                $row['reached_75'],
                $completed,
            ]);
        }
    }
}
