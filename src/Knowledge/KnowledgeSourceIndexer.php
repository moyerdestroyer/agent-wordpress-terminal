<?php

/**
 * Indexes one normalized Knowledge source.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Database\KnowledgeIndexRepository;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeSourceIndexer {
    private KnowledgeIndexRepository $index;
    private KnowledgeTextChunker $chunker;
    private EmbeddingService $embeddings;
    private KnowledgeVectorIndexInterface $vectors;

    public function __construct(
        ?KnowledgeIndexRepository $index = null,
        ?KnowledgeTextChunker $chunker = null,
        ?EmbeddingService $embeddings = null,
        ?KnowledgeVectorIndexInterface $vectors = null,
    ) {
        $this->index = $index ?? new KnowledgeIndexRepository();
        $this->chunker = $chunker ?? new KnowledgeTextChunker();
        $this->embeddings = $embeddings ?? new EmbeddingService();
        $this->vectors = $vectors ?? KnowledgeVectorIndex::resolve();
    }

    /**
     * @param array<string, mixed> $source
     * @return array{status: string, source_id: string, chunks: int, embedded: int}|null
     */
    public function index(array $source, string $now, int $run_id = 0): ?array {
        $content = $this->normalize_content((string) ($source['content'] ?? ''));

        if ('' === $content) {
            return null;
        }

        $source_id = (string) ($source['source_id'] ?? hash('sha256', $content));
        $content_hash = hash('sha256', $content);
        $record = $this->index->source_record($source_id);
        $index_profile = KnowledgeIndexProfile::value();
        $embedding_profile = $this->embeddings->profile();
        $content_unchanged =
            is_array($record)
            && hash_equals((string) ($record['content_hash'] ?? ''), $content_hash)
            && hash_equals((string) ($record['index_profile'] ?? ''), $index_profile);
        $semantic_eligible = !array_key_exists('semantic_eligible', $source) || (bool) $source['semantic_eligible'];
        $needs_embedding_backfill =
            $content_unchanged
            && $semantic_eligible
            && $this->embeddings->is_enabled()
            && $this->index->source_needs_embedding_profile($source_id, $embedding_profile);

        if ($content_unchanged && !$needs_embedding_backfill) {
            if (
                $semantic_eligible
                && $this->embeddings->is_enabled()
                && 'local' !== $this->vectors->health()['backend']
            ) {
                $this->sync_existing_vectors($source_id, $embedding_profile);
            }

            if ($run_id > 0) {
                $this->index->mark_source_seen($source_id, $run_id, (string) ($source['discovery_fingerprint'] ?? ''));
            }

            return [
                'status' => 'skipped',
                'source_id' => $source_id,
                'chunks' => 0,
                'embedded' => 0,
            ];
        }

        $old_chunk_ids = $this->index->chunk_ids_for_source($source_id);
        $content_type = (string) ($source['content_type'] ?? 'prose');
        $chunk_records = $this->chunker->chunks($content, $content_type);
        $embedding_texts = [];

        foreach ($chunk_records as $chunk) {
            $embedding_texts[] = $this->embedding_payload($source, $chunk);
        }

        $vectors = $semantic_eligible && $this->embeddings->is_enabled()
            ? $this->embed_in_batches($embedding_texts)
            : [];
        $stored_vectors = [];

        $this->index->begin_transaction();

        try {
            $this->index->delete_source_by_source_id($source_id);
            $index_id = $this->index->insert_source($source, $content, $now, $index_profile, $run_id);

            if ($index_id <= 0) {
                throw new \RuntimeException(__('Could not store Knowledge source.', 'agent-wordpress-terminal'));
            }

            foreach ($chunk_records as $chunk_index => $chunk) {
                $chunk_hash = hash('sha256', (string) ($embedding_texts[$chunk_index] ?? $chunk['text'] ?? ''));
                $chunk_id = KnowledgeChunkIdentity::make(
                    $source_id,
                    (string) $chunk['section_key'],
                    (int) $chunk['section_ordinal'],
                    $chunk_hash,
                );
                $embedding = $vectors[$chunk_index] ?? null;
                $metadata = [
                    'heading_path' => $chunk['heading_path'],
                    'section_key' => $chunk['section_key'],
                    'section_ordinal' => $chunk['section_ordinal'],
                    'page_start' => $chunk['page_start'],
                    'page_end' => $chunk['page_end'],
                    'word_count' => $chunk['word_count'],
                    'token_estimate' => $chunk['token_estimate'],
                    'chunker_version' => KnowledgeTextChunker::VERSION,
                    'index_format_version' => KnowledgeIndexProfile::FORMAT_VERSION,
                    'semantic_eligible' => $semantic_eligible,
                ];
                $this->index->insert_chunk($index_id, [
                    'chunk_index' => $chunk_index,
                    'chunk_id' => $chunk_id,
                    'chunk_hash' => $chunk_hash,
                    'text' => $chunk['text'],
                    'embedding' => $embedding,
                    'embedding_profile' => $embedding_profile,
                    'metadata' => $metadata,
                    'created_at' => $now,
                ]);

                if (null !== $embedding) {
                    $stored_vectors[] = [
                        'chunk_id' => $chunk_id,
                        'vector' => $embedding,
                        'metadata' => array_merge($metadata, [
                            'source_id' => $source_id,
                            'source_kind' => (string) ($source['kind'] ?? 'unknown'),
                        ]),
                    ];
                }
            }

            $this->index->commit();
        } catch (\Throwable $throwable) {
            $this->index->rollback();
            throw $throwable;
        }

        if ([] !== $old_chunk_ids) {
            $this->vectors->delete_chunks($old_chunk_ids);
        }

        if ([] !== $stored_vectors) {
            $this->vectors->upsert_chunks($stored_vectors, $embedding_profile);
        }

        return [
            'status' => 'indexed',
            'source_id' => $source_id,
            'chunks' => count($chunk_records),
            'embedded' => count($stored_vectors),
        ];
    }

    private function normalize_content(string $content): string {
        $content = wp_strip_all_tags($content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[ \t]+\n/u', "\n", $content);

        return trim(is_string($content) ? $content : '');
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $chunk
     */
    private function embedding_payload(array $source, array $chunk): string {
        $parts = array_filter(
            [
                trim((string) ($source['label'] ?? '')),
                trim((string) ($chunk['heading_path'] ?? '')),
                trim((string) ($chunk['text'] ?? '')),
            ],
            static fn(string $part): bool => '' !== $part,
        );

        return implode("\n", $parts);
    }

    private function sync_existing_vectors(string $source_id, string $profile): void {
        $chunks = [];

        foreach ($this->index->vector_chunks_for_source($source_id, $profile) as $row) {
            $vector = json_decode((string) ($row['embedding_json'] ?? ''), true);
            $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);

            if (!is_array($vector) || [] === $vector) {
                continue;
            }

            $chunks[] = [
                'chunk_id' => (string) ($row['chunk_id'] ?? ''),
                'vector' => array_values(array_map('floatval', $vector)),
                'metadata' => is_array($metadata) ? $metadata : [],
            ];
        }

        if ([] !== $chunks) {
            $this->vectors->upsert_chunks($chunks, $profile);
        }
    }

    /**
     * @param list<string> $texts
     * @return list<?list<float>>
     */
    private function embed_in_batches(array $texts): array {
        $out = [];

        foreach (array_chunk($texts, 32) as $batch) {
            foreach ($this->embeddings->embed_many($batch) as $vector) {
                $out[] = $vector;
            }
        }

        return $out;
    }
}
