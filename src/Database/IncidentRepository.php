<?php

/**
 * Incident database access.
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
 * Reads and writes awpt_incidents.
 */
final class IncidentRepository {
    /**
     * @param array<string, mixed> $data
     */
    public function create(int $session_id, array $data): ?int {
        $wpdb = WpDb::get();
        $now = current_time('mysql');

        $action_id = \AWPT\Support\ArrayKey::as_int($data['action_id'] ?? 0);
        $row = [
            'session_id' => $session_id,
            'kind' => sanitize_key((string) ($data['kind'] ?? 'php')),
            'source' => sanitize_text_field((string) ($data['source'] ?? '')),
            'attempted_action' => sanitize_key((string) ($data['attempted_action'] ?? '')),
            'error_text' => sanitize_textarea_field((string) ($data['error_text'] ?? '')),
            'diagnosis_json' => null,
            'status' => 'open',
            'created_at' => $now,
            'resolved_at' => null,
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($action_id > 0) {
            $row['action_id'] = $action_id;
            array_splice($formats, 4, 0, ['%d']);
        }

        $inserted = $wpdb->insert($wpdb->prefix . 'awpt_incidents', $row, $formats);

        if (false === $inserted) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    public function get(int $incident_id): ?array {
        $wpdb = WpDb::get();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'awpt_incidents WHERE id = %d', $incident_id),
            ARRAY_A,
        );

        return is_array($row) ? $this->format_row($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latest_open(int $session_id): ?array {
        $wpdb = WpDb::get();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'awpt_incidents
                WHERE session_id = %d AND status = %s
                ORDER BY id DESC LIMIT 1',
                $session_id,
                'open',
            ),
            ARRAY_A,
        );

        return is_array($row) ? $this->format_row($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list_open(int $session_id, int $limit = 5): array {
        $wpdb = WpDb::get();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'awpt_incidents
                WHERE session_id = %d AND status IN (%s, %s)
                ORDER BY id DESC LIMIT %d',
                $session_id,
                'open',
                'diagnosed',
                $limit,
            ),
            ARRAY_A,
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map($this->format_row(...), $rows);
    }

    /**
     * @param array<string, mixed> $diagnosis
     */
    public function mark_diagnosed(int $incident_id, array $diagnosis): void {
        $wpdb = WpDb::get();

        $wpdb->update(
            $wpdb->prefix . 'awpt_incidents',
            [
                'status' => 'diagnosed',
                'diagnosis_json' => wp_json_encode($diagnosis),
            ],
            ['id' => $incident_id],
            format: ['%s', '%s'],
            where_format: ['%d'],
        );
    }

    public function mark_resolved(int $incident_id): void {
        $wpdb = WpDb::get();

        $wpdb->update(
            $wpdb->prefix . 'awpt_incidents',
            [
                'status' => 'resolved',
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => $incident_id],
            format: ['%s', '%s'],
            where_format: ['%d'],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_row(array $row): array {
        $diagnosis = Json::decode_array((string) ($row['diagnosis_json'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'session_id' => (int) ($row['session_id'] ?? 0),
            'kind' => (string) ($row['kind'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'attempted_action' => (string) ($row['attempted_action'] ?? ''),
            'action_id' => is_numeric($row['action_id'] ?? null) ? (int) $row['action_id'] : null,
            'error_text' => (string) ($row['error_text'] ?? ''),
            'diagnosis' => $diagnosis,
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'resolved_at' => (string) ($row['resolved_at'] ?? ''),
        ];
    }
}
