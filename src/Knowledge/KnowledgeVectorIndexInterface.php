<?php

/**
 * Replaceable vector index contract.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * External adapters store vectors by AWPT's stable chunk ID and return those IDs.
 */
interface KnowledgeVectorIndexInterface {
    /**
     * @param list<array<string, mixed>> $chunks
     */
    public function upsert_chunks(array $chunks, string $profile): void;

    /**
     * @param list<string> $chunk_ids
     */
    public function delete_chunks(array $chunk_ids): void;

    /**
     * @param list<float> $query_vector
     * @return list<array{chunk_id: string, score: float}>
     */
    public function query(array $query_vector, string $profile, int $limit): array;

    /**
     * @return array{backend: string, available: bool, detail: string}
     */
    public function health(): array;
}
