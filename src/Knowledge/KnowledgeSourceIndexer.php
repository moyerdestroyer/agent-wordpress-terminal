<?php

/**
 * Indexes a single Knowledge source into the local cache.
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
 * Handles per-source hash checks, chunking, and optional embeddings.
 */
final class KnowledgeSourceIndexer {
    private KnowledgeIndexRepository $index;
    private KnowledgeTextChunker $chunker;
    private EmbeddingService $embeddings;

    public function __construct(
        ?KnowledgeIndexRepository $index = null,
        ?KnowledgeTextChunker $chunker = null,
        ?EmbeddingService $embeddings = null,
    ) {
        $this->index = $index ?? new KnowledgeIndexRepository();
        $this->chunker = $chunker ?? new KnowledgeTextChunker();
        $this->embeddings = $embeddings ?? new EmbeddingService();
    }

    /**
     * @param array<string, mixed> $source
     * @return array{status: string, source_id: string, chunks: int, embedded: int}|null
     */
    public function index(array $source, string $now): ?array {
        $content = trim(wp_strip_all_tags((string) ($source['content'] ?? '')));

        if ('' === $content) {
            return null;
        }

        $source_id = (string) ($source['source_id'] ?? hash('sha256', $content));
        $content_hash = hash('sha256', $content);
        $existing_hash = $this->index->content_hash_for_source_id($source_id);

        $content_unchanged = null !== $existing_hash && hash_equals($existing_hash, $content_hash);
        $needs_embedding_backfill =
            $content_unchanged && $this->embeddings->is_enabled() && $this->index->source_needs_embeddings($source_id);

        if ($content_unchanged && !$needs_embedding_backfill) {
            return [
                'status' => 'skipped',
                'source_id' => $source_id,
                'chunks' => 0,
                'embedded' => 0,
            ];
        }

        $this->index->delete_source_by_source_id($source_id);
        $index_id = $this->index->insert_source($source, $content, $now);

        if ($index_id <= 0) {
            return null;
        }

        return $this->store_chunks($index_id, $source_id, $content, $now);
    }

    /**
     * @return array{status: string, source_id: string, chunks: int, embedded: int}
     */
    private function store_chunks(int $index_id, string $source_id, string $content, string $now): array {
        $chunk_texts = $this->chunker->chunk($content);
        $vectors = $this->embeddings->is_enabled() ? $this->embed_in_batches($chunk_texts) : [];
        $chunk_count = 0;
        $embedded_count = 0;

        foreach ($chunk_texts as $chunk_index => $chunk_text) {
            $embedding = $vectors[$chunk_index] ?? null;
            $this->index->insert_chunk($index_id, $chunk_index, $chunk_text, $now, $embedding);
            ++$chunk_count;

            if (null !== $embedding) {
                ++$embedded_count;
            }
        }

        return [
            'status' => 'indexed',
            'source_id' => $source_id,
            'chunks' => $chunk_count,
            'embedded' => $embedded_count,
        ];
    }

    /**
     * @param list<string> $texts
     * @return list<?list<float>>
     */
    private function embed_in_batches(array $texts): array {
        if ([] === $texts) {
            return [];
        }

        $out = [];

        foreach (array_chunk($texts, 32) as $batch) {
            foreach ($this->embeddings->embed_many($batch) as $vector) {
                $out[] = $vector;
            }
        }

        return $out;
    }
}
