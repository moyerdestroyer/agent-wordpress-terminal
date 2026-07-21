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
final class KnowledgeIndexRepository {
    public function clear_index(): void {
        $wpdb = WpDb::get();

        $chunks_table = $wpdb->prefix . 'awpt_knowledge_chunks';
        $index_table = $wpdb->prefix . 'awpt_knowledge_index';

        $wpdb->query($wpdb->prepare('DELETE FROM %i', $chunks_table));
        $wpdb->query($wpdb->prepare('DELETE FROM %i', $index_table));
    }

    /**
     * @param array<string, mixed> $source
     */
    public function insert_source(array $source, string $content, string $now): int {
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

    /**
     * @param list<float>|null $embedding Optional embedding vector for hybrid retrieval.
     */
    public function insert_chunk(
        int $index_id,
        int $chunk_index,
        string $chunk_text,
        string $now,
        ?array $embedding = null,
    ): void {
        $wpdb = WpDb::get();
        $has_embedding = null !== $embedding && [] !== $embedding;
        $embedding_json = $has_embedding ? wp_json_encode(array_values($embedding)) : null;

        $wpdb->insert(
            $wpdb->prefix . 'awpt_knowledge_chunks',
            [
                'index_id' => $index_id,
                'chunk_index' => $chunk_index,
                'chunk_text' => $chunk_text,
                'embedding_json' => $embedding_json,
                'metadata_json' => wp_json_encode([
                    'retrieval' => $has_embedding ? 'hybrid' : 'keyword',
                ]),
                'char_count' => mb_strlen($chunk_text, 'UTF-8'),
                'created_at' => $now,
            ],
            format: ['%d', '%d', '%s', '%s', '%s', '%d', '%s'],
        );
    }

    /**
     * Load chunks that already have stored embeddings (capped for in-process similarity).
     *
     * @return list<array<string, mixed>>
     */
    public function list_chunks_with_embeddings(int $limit = 100, int $before_id = 0): array {
        $wpdb = WpDb::get();
        $limit = max(1, min($limit, 250));
        $cursor_clause = $before_id > 0 ? 'AND c.id < %d' : '';
        $sql = "SELECT c.id, c.chunk_text, c.chunk_index, c.embedding_json,
				i.source_kind, i.source_id, i.source_post_id, i.label, i.uri, i.metadata_json
			FROM {$wpdb->prefix}awpt_knowledge_chunks c
			INNER JOIN {$wpdb->prefix}awpt_knowledge_index i ON i.id = c.index_id
			WHERE c.embedding_json IS NOT NULL AND c.embedding_json != ''
			{$cursor_clause}
			ORDER BY c.id DESC
			LIMIT %d";
        $params = $before_id > 0 ? [$before_id, $limit] : [$limit];
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), output: \ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function count_chunks_with_embeddings(): int {
        $wpdb = WpDb::get();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE embedding_json IS NOT NULL AND embedding_json != ''",
            $wpdb->prefix . 'awpt_knowledge_chunks',
        ));
    }

    public function content_hash_for_source_id(string $source_id): ?string {
        return new KnowledgeIndexMaintenance()->content_hash_for_source_id($source_id);
    }

    public function source_needs_embeddings(string $source_id): bool {
        $wpdb = WpDb::get();
        $sql = "SELECT COUNT(*)
            FROM {$wpdb->prefix}awpt_knowledge_index i
            LEFT JOIN {$wpdb->prefix}awpt_knowledge_chunks c ON c.index_id = i.id
            WHERE i.source_id = %s
                AND (c.id IS NULL OR c.embedding_json IS NULL OR c.embedding_json = '')";

        return (int) $wpdb->get_var($wpdb->prepare($sql, $source_id)) > 0;
    }

    public function delete_source_by_source_id(string $source_id): void {
        new KnowledgeIndexMaintenance()->delete_source_by_source_id($source_id);
    }

    /**
     * @param list<string> $source_ids
     */
    public function delete_sources_not_in(array $source_ids): void {
        new KnowledgeIndexMaintenance()->delete_sources_not_in($source_ids);
    }

    public function count_sources(): int {
        $wpdb = WpDb::get();

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'awpt_knowledge_index'));
    }

    public function count_chunks(): int {
        $wpdb = WpDb::get();

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'awpt_knowledge_chunks'));
    }

    /**
     * @return array<string, int>
     */
    public function count_sources_by_kind(): array {
        $wpdb = WpDb::get();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT source_kind, COUNT(*) AS source_count FROM %i GROUP BY source_kind',
                $wpdb->prefix . 'awpt_knowledge_index',
            ),
            output: \ARRAY_A,
        );

        if (!is_array($rows)) {
            return [];
        }

        $counts = [];

        foreach ($rows as $row) {
            $kind = (string) ($row['source_kind'] ?? '');

            if ('' === $kind) {
                continue;
            }

            $counts[$kind] = (int) ($row['source_count'] ?? 0);
        }

        return $counts;
    }

    /**
     * Sample source labels for agent discovery (newest first).
     *
     * @return list<array{source_kind: string, label: string, uri: string}>
     */
    public function sample_source_labels(int $limit = 24, string $kind = ''): array {
        $wpdb = WpDb::get();
        $limit = max(1, min(50, $limit));
        $kind = sanitize_key($kind);

        if ('' !== $kind) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT source_kind, label, uri FROM %i WHERE source_kind = %s ORDER BY indexed_at DESC LIMIT %d',
                    $wpdb->prefix . 'awpt_knowledge_index',
                    $kind,
                    $limit,
                ),
                output: \ARRAY_A,
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT source_kind, label, uri FROM %i ORDER BY indexed_at DESC LIMIT %d',
                    $wpdb->prefix . 'awpt_knowledge_index',
                    $limit,
                ),
                output: \ARRAY_A,
            );
        }

        if (!is_array($rows)) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'source_kind' => (string) ($row['source_kind'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'uri' => (string) ($row['uri'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @param list<string> $tokens
     * @return list<array<string, mixed>>
     */
    public function search_chunks(array $tokens): array {
        $tokens = array_slice($tokens, 0, 8);

        if ([] === $tokens) {
            return [];
        }

        $fulltext_rows = $this->search_chunks_fulltext($tokens);

        if ([] !== $fulltext_rows) {
            return $fulltext_rows;
        }

        return $this->search_chunks_like($tokens);
    }

    /**
     * @param list<string> $tokens
     * @return list<array<string, mixed>>
     */
    private function search_chunks_fulltext(array $tokens): array {
        $wpdb = WpDb::get();
        $terms = [];

        foreach ($tokens as $token) {
            $token = preg_replace('/[^\p{L}\p{N}]+/u', '', $token);

            if (!is_string($token) || strlen($token) < 2) {
                continue;
            }

            $terms[] = '+' . $token . '*';
        }

        if ([] === $terms) {
            return [];
        }

        $boolean_query = implode(' ', $terms);
        $sql = "SELECT c.id, c.chunk_text, c.chunk_index, i.source_kind, i.source_id, i.source_post_id,
				i.label, i.uri, i.metadata_json,
				MATCH(c.chunk_text) AGAINST (%s IN BOOLEAN MODE) AS relevance
			FROM {$wpdb->prefix}awpt_knowledge_chunks c
			INNER JOIN {$wpdb->prefix}awpt_knowledge_index i ON i.id = c.index_id
			WHERE MATCH(c.chunk_text) AGAINST (%s IN BOOLEAN MODE)
			ORDER BY relevance DESC, i.indexed_at DESC
			LIMIT 200";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $boolean_query, $boolean_query), output: \ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<string> $tokens
     * @return list<array<string, mixed>>
     */
    private function search_chunks_like(array $tokens): array {
        $wpdb = WpDb::get();
        $like_clauses = [];
        $params = [];

        foreach ($tokens as $token) {
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
