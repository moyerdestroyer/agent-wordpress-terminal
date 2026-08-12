<?php

/**
 * Persists full AI log payloads for development inspection.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\Json;

if (!defined('ABSPATH')) {
    exit();
}

/** Stores request/response AI logs in awpt_ai_logs. */
final class AiLogRepository {
    /**
     * @param array<string, mixed> $entry Normalized AiLogger entry.
     */
    public function store(array $entry): void {
        global $wpdb;

        if (!$wpdb instanceof \wpdb || !method_exists($wpdb, 'insert')) {
            return;
        }

        $request_json = wp_json_encode($entry['request'] ?? null);
        $response_json = wp_json_encode($entry['response'] ?? null);
        $meta_json = wp_json_encode($entry['meta'] ?? []);

        $wpdb->insert(
            $wpdb->prefix . 'awpt_ai_logs',
            [
                'session_id' => (int) ($entry['session_id'] ?? 0),
                'event' => (string) ($entry['event'] ?? 'unknown'),
                'provider' => (string) ($entry['provider'] ?? ''),
                'model' => (string) ($entry['model'] ?? ''),
                'turn_id' => '' !== (string) ($entry['turn_id'] ?? '')
                    ? sanitize_key((string) $entry['turn_id'])
                    : null,
                'tool_round' => (int) ($entry['tool_round'] ?? 0),
                'outcome' => (string) ($entry['outcome'] ?? 'success'),
                'error_code' => (string) ($entry['error_code'] ?? ''),
                'duration_ms' => (int) ($entry['duration_ms'] ?? 0),
                'request_json' => is_string($request_json) ? $request_json : 'null',
                'response_json' => is_string($response_json) ? $response_json : 'null',
                'meta_json' => is_string($meta_json) ? $meta_json : '{}',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list_for_session(int $session_id, int $limit = 50): array {
        if ($session_id <= 0) {
            return [];
        }

        global $wpdb;

        if (!$wpdb instanceof \wpdb || !method_exists($wpdb, 'get_results')) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $table = $wpdb->prefix . 'awpt_ai_logs';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, session_id, event, provider, model, turn_id, tool_round, outcome, error_code,
                    duration_ms, request_json, response_json, meta_json, created_at
                FROM {$table}
                WHERE session_id = %d
                ORDER BY id DESC
                LIMIT %d",
                $session_id,
                $limit,
            ),
            ARRAY_A,
        );

        if (!is_array($rows)) {
            return [];
        }

        $out = [];

        foreach (array_reverse($rows) as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'session_id' => (int) ($row['session_id'] ?? 0),
                'event' => (string) ($row['event'] ?? ''),
                'provider' => (string) ($row['provider'] ?? ''),
                'model' => (string) ($row['model'] ?? ''),
                'turn_id' => (string) ($row['turn_id'] ?? ''),
                'tool_round' => (int) ($row['tool_round'] ?? 0),
                'outcome' => (string) ($row['outcome'] ?? ''),
                'error_code' => (string) ($row['error_code'] ?? ''),
                'duration_ms' => (int) ($row['duration_ms'] ?? 0),
                'request' => Json::decode_value((string) ($row['request_json'] ?? '')),
                'response' => Json::decode_value((string) ($row['response_json'] ?? '')),
                'meta' => Json::decode_array((string) ($row['meta_json'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function prune_older_than(int $days): void {
        $days = max(1, $days);
        global $wpdb;

        if (!$wpdb instanceof \wpdb || !method_exists($wpdb, 'query')) {
            return;
        }

        $table = $wpdb->prefix . 'awpt_ai_logs';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
            current_time('mysql'),
            $days,
        ));
    }
}
