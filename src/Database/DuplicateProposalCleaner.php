<?php

/**
 * Removes accidental duplicate open new-post proposals while preserving tool history.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\ActionOperations;
use AWPT\Support\Json;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

final class DuplicateProposalCleaner {
    /** @return list<int> Deleted action IDs. */
    public function cleanup_all(): array {
        $wpdb = WpDb::get();
        $table = $wpdb->prefix . 'awpt_actions';
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status IN ('proposed', 'approved') ORDER BY updated_at DESC, id DESC",
            output: \ARRAY_A,
        );

        if (!is_array($rows)) {
            return [];
        }

        $seen = [];
        $removed = [];
        $preview = new StagedPostPreview();

        foreach ($rows as $row) {
            $payload = Json::decode_array((string) ($row['payload_json'] ?? ''));

            if (ActionOperations::NEW_POST !== (string) ($payload['operation'] ?? '')) {
                continue;
            }

            $key = implode(':', [
                (string) ($row['session_id'] ?? 0),
                (string) ($payload['post_type'] ?? 'post'),
                sanitize_title((string) ($payload['post_title'] ?? '')),
            ]);

            if (!array_key_exists($key, $seen)) {
                $seen[$key] = true;
                continue;
            }

            $action_id = (int) ($row['id'] ?? 0);
            $preview->discard_preview_resources($payload);
            $deleted = $wpdb->delete($table, ['id' => $action_id], where_format: ['%d']);

            if (false !== $deleted && $action_id > 0) {
                $removed[] = $action_id;
            }
        }

        return $removed;
    }
}
