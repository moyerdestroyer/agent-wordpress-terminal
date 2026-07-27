<?php

/**
 * Message and tool-call persistence.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stores transcript messages and tool calls.
 */
final class MessageRepository {
    public function store_message(int $session_id, string $role, string $content, string $created_at): bool {
        $wpdb = WpDb::get();

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'awpt_messages',
            [
                'session_id' => $session_id,
                'role' => $role,
                'content' => $content,
                'created_at' => $created_at,
            ],
            format: ['%d', '%s', '%s', '%s'],
        );

        return false !== $inserted;
    }

    /**
     * @param array<int, mixed> $tool_calls
     */
    public function store_tool_calls(int $session_id, array $tool_calls, string $created_at): void {
        $wpdb = WpDb::get();

        foreach ($tool_calls as $tool_call) {
            if (!is_array($tool_call)) {
                continue;
            }

            $wpdb->insert(
                $wpdb->prefix . 'awpt_tool_calls',
                [
                    'session_id' => $session_id,
                    'tool_name' => (string) ($tool_call['tool'] ?? ''),
                    'input_json' => wp_json_encode($tool_call['input'] ?? []),
                    'output_json' => wp_json_encode($tool_call['output'] ?? null),
                    'status' => (string) ($tool_call['status'] ?? 'success'),
                    'created_at' => $created_at,
                ],
                format: ['%d', '%s', '%s', '%s', '%s', '%s'],
            );
        }
    }

    /**
     * Fetch recent persisted tool evidence for session-level reuse.
     *
     * @return list<array{tool: string, input: array<array-key, mixed>, output: array<array-key, mixed>, status: string}>
     */
    public function recent_tool_calls(int $session_id, int $limit = 160): array {
        $wpdb = WpDb::get();
        $limit = max(1, min(400, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tool_name, input_json, output_json, status
                FROM {$wpdb->prefix}awpt_tool_calls
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

        $calls = [];

        foreach (array_reverse($rows) as $row) {
            $input = json_decode((string) ($row['input_json'] ?? ''), true);
            $output = json_decode((string) ($row['output_json'] ?? ''), true);
            $calls[] = [
                'tool' => (string) ($row['tool_name'] ?? ''),
                'input' => is_array($input) ? $input : [],
                'output' => is_array($output) ? $output : [],
                'status' => (string) ($row['status'] ?? ''),
            ];
        }

        return $calls;
    }

    /**
     * Fetch session transcript messages, oldest first.
     *
     * @return array<int, array<string, string>>
     */
    public function session_messages(int $session_id, int $limit = 30): array {
        $wpdb = WpDb::get();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT role, content FROM {$wpdb->prefix}awpt_messages WHERE session_id = %d ORDER BY id DESC LIMIT %d",
                $session_id,
                $limit,
            ),
            ARRAY_A,
        );

        if (!$rows) {
            return [];
        }

        return array_reverse(array_map(static fn(array $row): array => [
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
        ], $rows));
    }

    /**
     * Fetch the most recent user message for a session.
     */
    public function latest_user_message(int $session_id): string {
        $wpdb = WpDb::get();

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT content FROM {$wpdb->prefix}awpt_messages WHERE session_id = %d AND role = 'user' ORDER BY id DESC LIMIT 1",
            $session_id,
        ));
    }

    /**
     * Fetch the most recent user message bodies for a session, oldest first. Used to
     * recover ground-truth URLs a model may have mistyped when reproducing them in a
     * tool call.
     *
     * @return list<string>
     */
    public function recent_user_message_contents(int $session_id, int $limit = 5): array {
        $wpdb = WpDb::get();

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT content FROM {$wpdb->prefix}awpt_messages"
            . " WHERE session_id = %d AND role = 'user' ORDER BY id DESC LIMIT %d",
            $session_id,
            $limit,
        ));

        if (!$rows) {
            return [];
        }

        return array_values(array_reverse(array_map('strval', $rows)));
    }
}
