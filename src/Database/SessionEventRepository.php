<?php

/**
 * Durable, provider-neutral session event persistence.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

final class SessionEventRepository {
    /** @param array<string, mixed> $event */
    public function append(int $session_id, array $event): int {
        if ($session_id <= 0) {
            return 0;
        }

        $wpdb = WpDb::get();
        $encoded = wp_json_encode(is_array($event['payload'] ?? null) ? $event['payload'] : []);
        $encoded = is_string($encoded) ? $encoded : '{}';
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'awpt_session_events',
            [
                'session_id' => $session_id,
                'turn_id' => sanitize_key((string) ($event['turn_id'] ?? '')),
                'ordinal' => max(0, (int) ($event['ordinal'] ?? 0)),
                'event_type' => sanitize_key((string) ($event['event_type'] ?? '')),
                'call_id' => '' !== (string) ($event['call_id'] ?? '')
                    ? sanitize_text_field((string) $event['call_id'])
                    : null,
                'payload_json' => $encoded,
                'token_estimate' => self::estimate_tokens($encoded),
                'covers_through_event_id' => isset($event['covers_through_event_id'])
                    ? (int) $event['covers_through_event_id']
                    : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s'],
        );

        return false === $inserted ? 0 : (int) $wpdb->insert_id;
    }

    /** @return list<array<string, mixed>> */
    public function list_for_projection(int $session_id, int $limit = 2_000): array {
        if ($session_id <= 0) {
            return [];
        }

        $wpdb = WpDb::get();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, turn_id, ordinal, event_type, call_id, payload_json, token_estimate, covers_through_event_id
            FROM {$wpdb->prefix}awpt_session_events
            WHERE session_id = %d
            ORDER BY id DESC LIMIT %d",
                $session_id,
                max(1, min(2_000, $limit)),
            ),
            ARRAY_A,
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_reverse(array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $row['payload'] = is_array($payload) ? $payload : [];

            return $row;
        }, $rows)));
    }

    public function latest_id(int $session_id): int {
        $wpdb = WpDb::get();

        return max(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(id) FROM {$wpdb->prefix}awpt_session_events WHERE session_id = %d",
                $session_id,
            )),
        );
    }

    /**
     * Existing sessions cannot reconstruct native tool call IDs. Import their
     * visible transcript once and keep legacy tool rows as explicitly labelled
     * evidence instead of inventing call/result pairs.
     */
    public function import_legacy_if_needed(int $session_id): void {
        $wpdb = WpDb::get();
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}awpt_session_events WHERE session_id = %d",
            $session_id,
        ));

        if ($count > 0) {
            return;
        }

        $messages = $wpdb->get_results($wpdb->prepare("SELECT id, role, content FROM {$wpdb->prefix}awpt_messages
            WHERE session_id = %d ORDER BY id ASC LIMIT 200", $session_id), ARRAY_A);
        $ordinal = 0;

        foreach (is_array($messages) ? $messages : [] as $message) {
            $role = (string) ($message['role'] ?? '');

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $this->append($session_id, [
                'turn_id' => 'legacy',
                'ordinal' => $ordinal++,
                'event_type' => $role,
                'payload' => [
                    'content' => (string) ($message['content'] ?? ''),
                    'legacy' => true,
                ],
            ]);
        }

        $tools = $wpdb->get_results($wpdb->prepare(
            "SELECT tool_name, input_json, output_json, status FROM {$wpdb->prefix}awpt_tool_calls
            WHERE session_id = %d ORDER BY id DESC LIMIT 40",
            $session_id,
        ), ARRAY_A);

        if (is_array($tools) && [] !== $tools) {
            $evidence = [];

            foreach (array_reverse($tools) as $tool) {
                $evidence[] = [
                    'tool' => (string) ($tool['tool_name'] ?? ''),
                    'status' => (string) ($tool['status'] ?? ''),
                    'input' => json_decode((string) ($tool['input_json'] ?? ''), true),
                    'output' => json_decode((string) ($tool['output_json'] ?? ''), true),
                ];
            }

            $this->append($session_id, [
                'turn_id' => 'legacy',
                'ordinal' => $ordinal,
                'event_type' => 'legacy_evidence',
                'payload' => ['calls' => $evidence],
            ]);
        }
    }

    public static function estimate_tokens(string $value): int {
        return (int) ceil(strlen($value) / 4);
    }
}
