<?php

/**
 * Local JSON-vector backend.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Database\KnowledgeIndexRepository;

if (!defined('ABSPATH')) {
    exit();
}

final class LocalKnowledgeVectorIndex implements KnowledgeVectorIndexInterface {
    private const MINIMUM_SIMILARITY = 0.30;

    private KnowledgeIndexRepository $repository;
    private EmbeddingService $embeddings;

    public function __construct(?KnowledgeIndexRepository $repository = null, ?EmbeddingService $embeddings = null) {
        $this->repository = $repository ?? new KnowledgeIndexRepository();
        $this->embeddings = $embeddings ?? new EmbeddingService();
    }

    public function upsert_chunks(array $chunks, string $profile): void {
        unset($chunks, $profile);

        // The canonical repository writes local vectors with each chunk transaction.
    }

    public function delete_chunks(array $chunk_ids): void {
        unset($chunk_ids);

        // Canonical source replacement removes local vector rows with their chunks.
    }

    public function query(array $query_vector, string $profile, int $limit): array {
        $best = [];
        $before_id = 0;

        do {
            $rows = $this->repository->list_chunks_with_embeddings(200, $before_id, $profile, count($query_vector));
            $page_ids = [];

            foreach ($rows as $row) {
                $row_id = (int) ($row['id'] ?? 0);

                if ($row_id > 0) {
                    $page_ids[] = $row_id;
                }

                $vector = json_decode((string) ($row['embedding_json'] ?? ''), true);

                if (!is_array($vector) || count($vector) !== count($query_vector)) {
                    continue;
                }

                $numeric = array_values(array_map('floatval', $vector));
                $score = $this->embeddings->cosine_similarity($query_vector, $numeric);

                if ($score < self::MINIMUM_SIMILARITY) {
                    continue;
                }

                $best[] = [
                    'chunk_id' => (string) ($row['chunk_id'] ?? ''),
                    'score' => $score,
                ];
            }

            usort($best, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
            $best = array_slice($best, 0, max($limit * 4, 80));

            if ([] !== $page_ids) {
                $before_id = min($page_ids);
            }
        } while (count($rows) === 200 && $before_id > 0);

        return array_slice($best, 0, $limit);
    }

    public function health(): array {
        return [
            'backend' => 'local',
            'available' => true,
            'detail' => __('Stored with the canonical MySQL chunk corpus.', 'agent-wordpress-terminal'),
        ];
    }
}
