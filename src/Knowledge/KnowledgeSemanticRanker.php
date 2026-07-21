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
    private const BATCH_SIZE = 75;

    private const MAX_CANDIDATES = 2000;

    private const RETAINED_RESULTS = 80;

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

        $scored = $this->rank_batches($query_vector);

        usort(
            $scored,
            static fn(array $left, array $right): int => (
                (float) ($right['score'] ?? 0.0) <=> (float) ($left['score'] ?? 0.0)
            ),
        );

        return array_slice($scored, 0, 40);
    }

    /**
     * Keep each wpdb result small: decoded vectors consume far more PHP memory than their JSON representation.
     *
     * @param list<float> $query_vector
     * @return list<array<string, mixed>>
     */
    private function rank_batches(array $query_vector): array {
        $scored = [];
        $before_id = 0;
        $processed = 0;

        while ($processed < self::MAX_CANDIDATES) {
            $batch_limit = min(self::BATCH_SIZE, self::MAX_CANDIDATES - $processed);
            $rows = $this->index->list_chunks_with_embeddings($batch_limit, $before_id);

            if ([] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                $row_id = (int) ($row['id'] ?? 0);

                if ($row_id > 0 && (0 === $before_id || $row_id < $before_id)) {
                    $before_id = $row_id;
                }

                $result = $this->score_row($row, $query_vector);

                if (null !== $result) {
                    $scored[] = $result;
                }
            }

            $processed += count($rows);
            $scored = $this->retain_best($scored);

            if (count($rows) < $batch_limit || $before_id <= 0) {
                break;
            }
        }

        return $scored;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<float>          $query_vector
     * @return array<string, mixed>|null
     */
    private function score_row(array $row, array $query_vector): ?array {
        $vector = $this->decode_vector((string) ($row['embedding_json'] ?? ''));

        if (null === $vector) {
            return null;
        }

        $similarity = $this->embeddings->cosine_similarity($query_vector, $vector);

        if ($similarity < 0.55) {
            return null;
        }

        $metadata_raw = json_decode((string) ($row['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata_raw) ? $metadata_raw : [];

        return [
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

    /**
     * @param list<array<string, mixed>> $scored
     * @return list<array<string, mixed>>
     */
    private function retain_best(array $scored): array {
        if (count($scored) <= self::RETAINED_RESULTS) {
            return $scored;
        }

        usort(
            $scored,
            static fn(array $left, array $right): int => (
                (float) ($right['score'] ?? 0.0) <=> (float) ($left['score'] ?? 0.0)
            ),
        );

        return array_slice($scored, 0, self::RETAINED_RESULTS);
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
