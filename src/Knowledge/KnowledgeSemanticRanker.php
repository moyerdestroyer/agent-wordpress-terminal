<?php

/**
 * Embedding-based ranking for Knowledge chunks.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Database\KnowledgeIndexRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Scores stored chunk embeddings against a query vector.
 */
final class KnowledgeSemanticRanker {
    private EmbeddingService $embeddings;
    private KnowledgeIndexRepository $index;

    public function __construct(?EmbeddingService $embeddings = null, ?KnowledgeIndexRepository $index = null) {
        $this->embeddings = $embeddings ?? new EmbeddingService();
        $this->index = $index ?? new KnowledgeIndexRepository();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rank(string $query): array {
        if (!$this->embeddings->is_enabled()) {
            return [];
        }

        $query_vector = $this->embeddings->embed($query);

        if (null === $query_vector) {
            return [];
        }

        $scored = [];

        foreach ($this->index->list_chunks_with_embeddings() as $row) {
            $vector = $this->decode_vector((string) ($row['embedding_json'] ?? ''));

            if (null === $vector) {
                continue;
            }

            $similarity = $this->embeddings->cosine_similarity($query_vector, $vector);

            if ($similarity < 0.55) {
                continue;
            }

            $metadata_raw = json_decode((string) ($row['metadata_json'] ?? ''), true);
            $metadata = is_array($metadata_raw) ? $metadata_raw : [];
            $scored[] = [
                'id' => (int) ($row['id'] ?? 0),
                'source_kind' => (string) ($row['source_kind'] ?? ''),
                'source_id' => (string) ($row['source_id'] ?? ''),
                'source_post_id' => null !== ($row['source_post_id'] ?? null) ? (int) $row['source_post_id'] : null,
                'label' => (string) ($row['label'] ?? ''),
                'uri' => (string) ($row['uri'] ?? ''),
                'excerpt' => $this->excerpt((string) ($row['chunk_text'] ?? '')),
                'score' => $similarity,
                'match' => 'embedding',
                'metadata' => $metadata,
            ];
        }

        usort($scored, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($scored, 0, 40);
    }

    /**
     * @return list<float>|null
     */
    private function decode_vector(string $json): ?array {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || [] === $decoded) {
            return null;
        }

        $vector = [];

        foreach ($decoded as $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $vector[] = (float) $value;
        }

        return [] === $vector ? null : $vector;
    }

    private function excerpt(string $text): string {
        $stripped = preg_replace('/\s+/', ' ', wp_strip_all_tags($text));
        $plain = trim(is_string($stripped) ? $stripped : $text);

        if (mb_strlen($plain, 'UTF-8') <= 420) {
            return $plain;
        }

        return mb_substr($plain, 0, 420, 'UTF-8') . '...';
    }
}
