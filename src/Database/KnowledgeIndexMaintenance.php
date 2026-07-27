<?php

/**
 * Incremental knowledge index maintenance helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Hash lookups and selective source deletion for rebuilds.
 */
final class KnowledgeIndexMaintenance {
    public function content_hash_for_source_id(string $source_id): ?string {
        $wpdb = WpDb::get();
        $hash = $wpdb->get_var($wpdb->prepare(
            'SELECT content_hash FROM %i WHERE source_id = %s LIMIT 1',
            $wpdb->prefix . 'awpt_knowledge_index',
            $source_id,
        ));

        return is_string($hash) && '' !== $hash ? $hash : null;
    }

    public function delete_source_by_source_id(string $source_id): void {
        $wpdb = WpDb::get();
        $index_table = $wpdb->prefix . 'awpt_knowledge_index';
        $chunks_table = $wpdb->prefix . 'awpt_knowledge_chunks';
        $index_id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE source_id = %s LIMIT 1',
            $index_table,
            $source_id,
        ));

        if (!is_numeric($index_id)) {
            return;
        }

        $wpdb->delete($chunks_table, ['index_id' => (int) $index_id], ['%d']);
        $wpdb->delete($index_table, ['id' => (int) $index_id], ['%d']);
    }

    /**
     * @param list<string> $source_ids
     */
    public function delete_sources_not_in(array $source_ids): void {
        if ([] === $source_ids) {
            new KnowledgeIndexRepository()->clear_index();

            return;
        }

        $wpdb = WpDb::get();
        $index_table = $wpdb->prefix . 'awpt_knowledge_index';
        $chunks_table = $wpdb->prefix . 'awpt_knowledge_chunks';
        $placeholders = implode(',', array_fill(0, count($source_ids), '%s'));
        $sql = "SELECT id FROM {$index_table} WHERE source_id NOT IN ({$placeholders})";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built dynamically from count.
        $rows = $wpdb->get_col($wpdb->prepare($sql, ...$source_ids));

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $index_id) {
            $id = (int) $index_id;
            $wpdb->delete($chunks_table, ['index_id' => $id], ['%d']);
            $wpdb->delete($index_table, ['id' => $id], ['%d']);
        }
    }

    /**
     * Remove sources not discovered by a completed reconciliation run.
     *
     * @return list<string> Stable chunk IDs removed from the canonical corpus.
     */
    public function delete_sources_not_seen_in_run(int $run_id): array {
        $wpdb = WpDb::get();
        $index_table = $wpdb->prefix . 'awpt_knowledge_index';
        $chunks_table = $wpdb->prefix . 'awpt_knowledge_chunks';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT i.id, c.chunk_id
             FROM {$index_table} i
             LEFT JOIN {$chunks_table} c ON c.index_id = i.id
             WHERE i.last_seen_run_id IS NULL OR i.last_seen_run_id != %d", $run_id), output: \ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $index_ids = [];
        $chunk_ids = [];

        foreach ($rows as $row) {
            $index_ids[(int) ($row['id'] ?? 0)] = true;
            $chunk_id = (string) ($row['chunk_id'] ?? '');

            if ('' !== $chunk_id) {
                $chunk_ids[] = $chunk_id;
            }
        }

        foreach (array_keys($index_ids) as $index_id) {
            if ($index_id <= 0) {
                continue;
            }

            $wpdb->delete($chunks_table, ['index_id' => $index_id], ['%d']);
            $wpdb->delete($index_table, ['id' => $index_id], ['%d']);
        }

        return array_values(array_unique($chunk_ids));
    }
}
