<?php

/**
 * Knowledge index database access.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads and writes awpt_knowledge_* tables.
 */
final class KnowledgeIndexRepository
{
    public function clear_index(): void
    {
        $wpdb = WpDb::get();

        $chunks_table = $wpdb->prefix . 'awpt_knowledge_chunks';
        $index_table = $wpdb->prefix . 'awpt_knowledge_index';

        $wpdb->query($wpdb->prepare('DELETE FROM %i', $chunks_table));
        $wpdb->query($wpdb->prepare('DELETE FROM %i', $index_table));
    }

    /**
     * @param array<string, mixed> $source
     */
    public function insert_source(array $source, string $content, string $now): int
    {
        $wpdb = WpDb::get();

        $metadata = is_array($source['metadata'] ?? null) ? $source['metadata'] : [];
        $path = (string) ($source['path'] ?? '');

        $wpdb->insert(
            $wpdb->prefix . 'awpt_knowledge_index',
            [
                'source_kind' => (string) ($source['kind'] ?? 'unknown'),
                'source_id' => (string) ($source['source_id'] ?? hash('sha256', $content)),
                'source_post_id' => array_key_exists('post_id', $source) ? (int) $source['post_id'] : null,
                'source_path_hash' => '' !== $path ? hash('sha256', $path) : '',
                'label' => mb_substr(
                    (string) ($source['label'] ?? __('Untitled source', 'agent-wordpress-terminal')),
                    0,
                    191,
                ),
                'uri' => (string) ($source['uri'] ?? ''),
                'content_hash' => hash('sha256', $content),
                'modified_at' => '' !== (string) ($source['modified_at'] ?? '')
                    ? (string) $source['modified_at']
                    : null,
                'indexed_at' => $now,
                'metadata_json' => wp_json_encode($metadata),
            ],
            format: ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );

        return (int) $wpdb->insert_id;
    }

    public function insert_chunk(int $index_id, int $chunk_index, string $chunk_text, string $now): void
    {
        $wpdb = WpDb::get();

        $wpdb->insert(
            $wpdb->prefix . 'awpt_knowledge_chunks',
            [
                'index_id' => $index_id,
                'chunk_index' => $chunk_index,
                'chunk_text' => $chunk_text,
                'embedding_json' => null,
                'metadata_json' => wp_json_encode(['retrieval' => 'keyword']),
                'char_count' => mb_strlen($chunk_text, 'UTF-8'),
                'created_at' => $now,
            ],
            format: ['%d', '%d', '%s', '%s', '%s', '%d', '%s'],
        );
    }

    public function count_sources(): int
    {
        $wpdb = WpDb::get();

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'awpt_knowledge_index'));
    }

    public function count_chunks(): int
    {
        $wpdb = WpDb::get();

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'awpt_knowledge_chunks'));
    }

    /**
     * @param list<string> $tokens
     * @return list<array<string, mixed>>
     */
    public function search_chunks(array $tokens): array
    {
        $wpdb = WpDb::get();

        $like_clauses = [];
        $params = [];

        foreach (array_slice($tokens, 0, 8) as $token) {
            $like = '%' . $wpdb->esc_like($token) . '%';
            $like_clauses[] = '(c.chunk_text LIKE %s OR i.label LIKE %s OR i.metadata_json LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql =
            "SELECT c.id, c.chunk_text, c.chunk_index, i.source_kind, i.source_id, i.source_post_id,
				i.label, i.uri, i.metadata_json
			FROM {$wpdb->prefix}awpt_knowledge_chunks c
			INNER JOIN {$wpdb->prefix}awpt_knowledge_index i ON i.id = c.index_id
			WHERE "
            . implode(' OR ', $like_clauses)
            . '
			ORDER BY i.indexed_at DESC
			LIMIT 200';

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), output: \ARRAY_A);

        return is_array($rows) ? $rows : [];
    }
}
