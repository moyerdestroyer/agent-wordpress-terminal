<?php

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

/** Stores bounded, session-scoped preview evidence outside options and post meta. */
final class CaptureRepository {
    /** @param array<string, mixed> $capture */
    public function create(array $capture): ?int {
        $wpdb = WpDb::get();
        $action_id = (int) ($capture['action_id'] ?? 0);
        $post_id = (int) ($capture['post_id'] ?? 0);
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'awpt_captures',
            [
                'session_id' => (int) ($capture['session_id'] ?? 0),
                'action_id' => $action_id > 0 ? $action_id : null,
                'post_id' => $post_id > 0 ? $post_id : null,
                'url' => esc_url_raw((string) ($capture['url'] ?? '')),
                'viewport_json' => wp_json_encode($capture['viewport'] ?? []),
                'dom_snapshot' => (string) ($capture['dom_snapshot'] ?? ''),
                'image_data' => (string) ($capture['image_data'] ?? ''),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'],
        );

        return false === $inserted ? null : (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed>|null */
    public function latest_for_session(int $session_id): ?array {
        $wpdb = WpDb::get();
        $captures = $wpdb->prefix . 'awpt_captures';
        $sessions = $wpdb->prefix . 'awpt_sessions';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.* FROM {$captures} c INNER JOIN {$sessions} s ON s.id = c.session_id WHERE c.session_id = %d AND s.user_id = %d ORDER BY c.id DESC LIMIT 1",
                $session_id,
                get_current_user_id(),
            ),
            \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function list_for_action(int $action_id): array {
        $wpdb = WpDb::get();
        $captures = $wpdb->prefix . 'awpt_captures';
        $sessions = $wpdb->prefix . 'awpt_sessions';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.* FROM {$captures} c INNER JOIN {$sessions} s ON s.id = c.session_id WHERE c.action_id = %d AND s.user_id = %d ORDER BY c.id DESC LIMIT 8",
                $action_id,
                get_current_user_id(),
            ),
            \ARRAY_A,
        );

        return is_array($rows) ? array_values($rows) : [];
    }
}
