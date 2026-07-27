<?php

/**
 * Embedding-based Knowledge ranking.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Database\KnowledgeIndexRepository;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeSemanticRanker {
    private EmbeddingService $embeddings;
    private KnowledgeIndexRepository $index;
    private KnowledgeVectorIndexInterface $vectors;

    public function __construct(
        ?EmbeddingService $embeddings = null,
        ?KnowledgeIndexRepository $index = null,
        ?KnowledgeVectorIndexInterface $vectors = null,
    ) {
        $this->embeddings = $embeddings ?? new EmbeddingService();
        $this->index = $index ?? new KnowledgeIndexRepository();
        $this->vectors = $vectors ?? KnowledgeVectorIndex::resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rank(string $query): array {
        if (!$this->embeddings->is_enabled()) {
            return [];
        }

        $query_vector = $this->embeddings->embed($query);

        if (null === $query_vector || [] === $query_vector) {
            return [];
        }

        $matches = $this->vectors->query($query_vector, $this->embeddings->profile(), 80);
        $scores = [];

        foreach ($matches as $match) {
            $chunk_id = (string) ($match['chunk_id'] ?? '');

            if ('' !== $chunk_id) {
                $scores[$chunk_id] = (float) ($match['score'] ?? 0.0);
            }
        }

        $rows = $this->index->chunks_by_chunk_ids(array_keys($scores));
        $ranked = [];
        $backend = $this->vectors->health()['backend'];

        foreach ($rows as $row) {
            $chunk_id = (string) ($row['chunk_id'] ?? '');

            if (!array_key_exists($chunk_id, $scores)) {
                continue;
            }

            $source_metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
            $chunk_metadata = json_decode((string) ($row['chunk_metadata_json'] ?? ''), true);
            $ranked[] = [
                'id' => (int) ($row['id'] ?? 0),
                'chunk_id' => $chunk_id,
                'source_kind' => (string) ($row['source_kind'] ?? ''),
                'source_id' => (string) ($row['source_id'] ?? ''),
                'source_post_id' => null !== ($row['source_post_id'] ?? null) ? (int) $row['source_post_id'] : null,
                'label' => (string) ($row['label'] ?? ''),
                'uri' => (string) ($row['uri'] ?? ''),
                'excerpt' => $this->excerpt((string) ($row['chunk_text'] ?? '')),
                'score' => $scores[$chunk_id],
                'match' => 'embedding',
                'retrieval_backend' => $backend,
                'metadata' => array_merge(
                    is_array($source_metadata) ? $source_metadata : [],
                    is_array($chunk_metadata) ? $chunk_metadata : [],
                ),
            ];
        }

        usort($ranked, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($ranked, 0, 40);
    }

    private function excerpt(string $text): string {
        $plain = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));

        return mb_strlen($plain, 'UTF-8') > 420 ? mb_substr($plain, 0, 420, 'UTF-8') . '…' : $plain;
    }
}
