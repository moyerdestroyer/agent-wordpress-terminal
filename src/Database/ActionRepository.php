<?php

/**
 * Action database access.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\ActionOperations;
use AWPT\Support\Json;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads and writes awpt_actions.
 */
final class ActionRepository {
    /**
     * @param array<string, mixed> $payload
     * @return int|null Inserted action ID.
     */
    public function create(
        int $session_id,
        string $title,
        string $description,
        array $payload,
        array $options = [],
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
                'status' => sanitize_key((string) ($options['status'] ?? 'proposed')),
                'turn_id' => '' !== (string) ($options['turn_id'] ?? '')
                    ? sanitize_key((string) $options['turn_id'])
                    : null,
                'proposal_key' => '' !== (string) ($options['proposal_key'] ?? '')
                    ? sanitize_key((string) $options['proposal_key'])
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            format: ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );

        if (false === $inserted) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    public function update_status(int $action_id, string $status): void {
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

    public function mark_applied(int $action_id): void {
        $this->update_status($action_id, 'applied');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update_payload(int $action_id, array $payload): void {
        $wpdb = WpDb::get();

        $wpdb->update(
            $wpdb->prefix . 'awpt_actions',
            [
                'payload_json' => wp_json_encode($this->sanitize_payload($payload)),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $action_id],
            format: ['%s', '%s'],
            where_format: ['%d'],
        );
    }

    /**
     * Replace the editable fields of an existing proposal and return it to review.
     *
     * @param array<string, mixed> $payload
     */
    public function revise(int $action_id, string $title, string $description, array $payload): bool {
        $wpdb = WpDb::get();

        $updated = $wpdb->update(
            $wpdb->prefix . 'awpt_actions',
            [
                'title' => $title,
                'description' => $description,
                'payload_json' => wp_json_encode($this->sanitize_payload($payload)),
                'status' => 'proposed',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $action_id],
            format: ['%s', '%s', '%s', '%s', '%s'],
            where_format: ['%d'],
        );

        return false !== $updated;
    }

    /**
     * Return active proposals that belong to the current user and session.
     *
     * @return list<array<string, mixed>>
     */
    public function list_open_for_session(int $session_id, int $limit = 10): array {
        $wpdb = WpDb::get();
        $limit = max(1, min(25, $limit));
        $actions = $wpdb->prefix . 'awpt_actions';
        $sessions = $wpdb->prefix . 'awpt_sessions';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.* FROM {$actions} a INNER JOIN {$sessions} s ON s.id = a.session_id"
                . " WHERE a.session_id = %d AND s.user_id = %d AND a.status IN ('proposed', 'approved')"
                . ' ORDER BY a.updated_at DESC LIMIT %d',
                $session_id,
                get_current_user_id(),
                $limit,
            ),
            output: \ARRAY_A,
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /** Return the newest open new-post proposal in a session. */
    public function latest_open_new_post_for_session(int $session_id): ?array {
        foreach ($this->list_open_for_session($session_id, 25) as $action) {
            $payload = $this->decode_payload($action);

            if (ActionOperations::NEW_POST === (string) ($payload['operation'] ?? '')) {
                return $action;
            }
        }

        return null;
    }

    /** Find the action produced by one logical proposal slot in a turn. */
    public function find_by_turn_key(int $session_id, string $turn_id, string $proposal_key): ?array {
        if ($session_id <= 0 || '' === $turn_id || '' === $proposal_key) {
            return null;
        }

        $wpdb = WpDb::get();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}awpt_actions WHERE session_id = %d AND turn_id = %s AND proposal_key = %s LIMIT 1",
                $session_id,
                sanitize_key($turn_id),
                sanitize_key($proposal_key),
            ),
            output: \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /** Delete one accessible proposal row. Preview resources must be discarded first. */
    public function delete(int $action_id): bool {
        if (null === $this->get_accessible_row($action_id)) {
            return false;
        }

        $wpdb = WpDb::get();
        $deleted = $wpdb->delete($wpdb->prefix . 'awpt_actions', ['id' => $action_id], where_format: ['%d']);

        return false !== $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_accessible_row(int $action_id): ?array {
        $wpdb = WpDb::get();

        $actions = $wpdb->prefix . 'awpt_actions';
        $sessions = $wpdb->prefix . 'awpt_sessions';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT a.* FROM {$actions} a INNER JOIN {$sessions} s ON s.id = a.session_id WHERE a.id = %d AND s.user_id = %d",
                $action_id,
                get_current_user_id(),
            ),
            output: \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function format_action(int $action_id): ?array {
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
            'turn_id' => (string) ($row['turn_id'] ?? ''),
            'proposal_key' => (string) ($row['proposal_key'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function decode_payload(array $action): array {
        return Json::decode_array((string) ($action['payload_json'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize_payload(array $payload): array {
        return new ActionPayloadSanitizer()->sanitize($payload);
    }
}
