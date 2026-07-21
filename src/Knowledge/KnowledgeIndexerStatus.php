<?php

/**
 * Knowledge index status payload builder.
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
 * Assembles Knowledge status for the admin UI and REST.
 */
final class KnowledgeIndexerStatus {
    private KnowledgeIndexRepository $index;
    private EmbeddingService $embeddings;

    public function __construct(?KnowledgeIndexRepository $index = null, ?EmbeddingService $embeddings = null) {
        $this->index = $index ?? new KnowledgeIndexRepository();
        $this->embeddings = $embeddings ?? new EmbeddingService();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array {
        $source_count = $this->index->count_sources();
        $source_kinds = $this->index->count_sources_by_kind();
        $counts = KnowledgeIndexer::cached_counts();
        $chunk_count = $counts['chunk_count'];
        $embedded_count = $counts['embedded_chunks'];

        return [
            'source_count' => $source_count,
            'source_kinds' => $source_kinds,
            'chunk_count' => $chunk_count,
            'stale' => '1' === (string) get_option('awpt_knowledge_stale', '0'),
            'needs_rebuild' => 0 === $source_count || '1' === (string) get_option('awpt_knowledge_stale', '0'),
            'last_indexed_at' => (string) get_option('awpt_knowledge_last_indexed_at', ''),
            'last_error' => (string) get_option('awpt_knowledge_last_error', ''),
            'progress' => KnowledgeIndexer::progress(),
            'embedding' => $this->embedding_status($embedded_count, $chunk_count),
            'filesystem' => [
                'allowed_roots' => new FilesystemSourceReader()->allowed_roots(),
                'max_file_size' => (int) get_option(
                    'awpt_knowledge_max_file_size',
                    FilesystemAccessPolicy::DEFAULT_MAX_FILE_SIZE,
                ),
            ],
            'repository' => new KnowledgeRepository()->status(),
            'site_content_index' => array_merge(new KnowledgeRepository()->site_content_index_stats(), [
                'indexed' => (int) ($source_kinds['wp_content'] ?? 0),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function embedding_status(int $embedded_count, int $chunk_count): array {
        $available = $this->embeddings->is_available();
        $enabled = $this->embeddings->is_enabled();
        $provider = $this->embeddings->provider_label();
        $model = $this->embeddings->model();
        $last_error = $this->embeddings->last_error();

        if ('' !== $last_error) {
            $label = sprintf(
                /* translators: %s: embeddings provider error */
                __('Keyword retrieval active; embeddings failed: %s', 'agent-wordpress-terminal'),
                $last_error,
            );
        } elseif (!$available) {
            $label = __(
                'Keyword retrieval active; add an OpenRouter or OpenAI API key to enable embeddings.',
                'agent-wordpress-terminal',
            );
        } elseif (!$enabled) {
            $label = __('Embeddings available but disabled; keyword retrieval only.', 'agent-wordpress-terminal');
        } elseif ($embedded_count > 0) {
            $label = sprintf(
                /* translators: 1: embedded chunk count, 2: total chunk count, 3: provider, 4: model */
                __('Hybrid retrieval (%1$d/%2$d chunks embedded via %3$s · %4$s).', 'agent-wordpress-terminal'),
                $embedded_count,
                $chunk_count,
                $provider,
                $model,
            );
        } else {
            $label = sprintf(
                /* translators: 1: provider, 2: model */
                __(
                    'Embeddings enabled via %1$s · %2$s; rebuild the index to embed chunks.',
                    'agent-wordpress-terminal',
                ),
                $provider,
                $model,
            );
        }

        return [
            'available' => $available,
            'enabled' => $enabled,
            'provider' => $provider,
            'model' => $model,
            'embedded_chunks' => $embedded_count,
            'last_error' => $last_error,
            'label' => $label,
        ];
    }
}
