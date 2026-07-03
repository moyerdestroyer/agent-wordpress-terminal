<?php

/**
 * Action database access.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\Json;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads and writes awpt_actions.
 */
final class ActionRepository
{
    /**
     * @param array<string, mixed> $payload
     * @return int|null Inserted action ID.
     */
    public function create(
        int $session_id,
        string $title,
        string $description,
        array $payload,
        string $status = 'proposed',
    ): ?int {
        $wpdb = WpDb::get();

        $now = current_time('mysql');
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'awpt_actions',
            [
                'session_id' => $session_id,
                'title' => $title,
                'description' => $description,
                'payload_json' => wp_json_encode($this->sanitize_payload($payload)),
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            format: ['%d', '%s', '%s', '%s', '%s', '%s', '%s'],
        );

        if (false === $inserted) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    public function update_status(int $action_id, string $status): void
    {
        $wpdb = WpDb::get();

        $wpdb->update(
            $wpdb->prefix . 'awpt_actions',
            [
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $action_id],
            format: ['%s', '%s'],
            where_format: ['%d'],
        );
    }

    public function mark_applied(int $action_id): void
    {
        $this->update_status($action_id, 'applied');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_accessible_row(int $action_id): ?array
    {
        $wpdb = WpDb::get();

        $actions = $wpdb->prefix . 'awpt_actions';
        $sessions = $wpdb->prefix . 'awpt_sessions';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT a.* FROM {$actions} a INNER JOIN {$sessions} s ON s.id = a.session_id WHERE a.id = %d",
                $action_id,
            ),
            output: \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function format_action(int $action_id): ?array
    {
        $row = $this->get_accessible_row($action_id);

        if (null === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'session_id' => (int) $row['session_id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'payload' => Json::decode_array((string) $row['payload_json']),
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function decode_payload(array $action): array
    {
        return Json::decode_array((string) ($action['payload_json'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize_payload(array $payload): array
    {
        return new ActionPayloadSanitizer()->sanitize($payload);
    }
}
