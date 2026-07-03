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
        $clean = [
            'operation' => sanitize_key((string) ($payload['operation'] ?? 'content_update')),
            'post_id' => absint($payload['post_id'] ?? 0),
        ];

        if (array_key_exists('post_title', $payload)) {
            $clean['post_title'] = sanitize_text_field((string) $payload['post_title']);
        }

        if (array_key_exists('post_type', $payload)) {
            $clean['post_type'] = sanitize_text_field((string) $payload['post_type']);
        }

        if (array_key_exists('post_status', $payload)) {
            $clean['post_status'] = sanitize_text_field((string) $payload['post_status']);
        }

        if (array_key_exists('original_post_title', $payload)) {
            $clean['original_post_title'] = sanitize_text_field((string) $payload['original_post_title']);
        }

        if (array_key_exists('post_content', $payload)) {
            $clean['post_content'] = wp_kses_post((string) $payload['post_content']);
        }

        if (array_key_exists('original_post_content', $payload)) {
            $clean['original_post_content'] = wp_kses_post((string) $payload['original_post_content']);
        }

        if (array_key_exists('preview_url', $payload)) {
            $clean['preview_url'] = esc_url_raw((string) $payload['preview_url']);
        }

        if (array_key_exists('affected', $payload)) {
            $clean['affected'] = sanitize_textarea_field((string) $payload['affected']);
        }

        return $clean;
    }
}
