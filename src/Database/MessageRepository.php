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
final class MessageRepository
{
    public function store_message(int $session_id, string $role, string $content, string $created_at): void
    {
        $wpdb = WpDb::get();

        $wpdb->insert(
            $wpdb->prefix . 'awpt_messages',
            [
                'session_id' => $session_id,
                'role' => $role,
                'content' => $content,
                'created_at' => $created_at,
            ],
            format: ['%d', '%s', '%s', '%s'],
        );
    }

    /**
     * @param array<int, mixed> $tool_calls
     */
    public function store_tool_calls(int $session_id, array $tool_calls, string $created_at): void
    {
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

    public function clear_transcript(int $session_id): void
    {
        $wpdb = WpDb::get();

        $wpdb->delete($wpdb->prefix . 'awpt_messages', ['session_id' => $session_id], where_format: ['%d']);
        $wpdb->delete($wpdb->prefix . 'awpt_tool_calls', ['session_id' => $session_id], where_format: ['%d']);
    }
}
