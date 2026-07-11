<?php

/**
 * Local Knowledge index cache.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Database\Installer;
use AWPT\Database\KnowledgeIndexRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds AWPT's local retrieval cache from Knowledge and safe read-only sources.
 */
final class KnowledgeIndexer {
    private KnowledgeIndexRepository $index;
    private KnowledgeSourceIndexer $source_indexer;
    private EmbeddingService $embeddings;

    public function __construct(
        ?KnowledgeIndexRepository $index = null,
        ?KnowledgeSourceIndexer $source_indexer = null,
        ?EmbeddingService $embeddings = null,
    ) {
        $this->index = $index ?? new KnowledgeIndexRepository();
        $this->embeddings = $embeddings ?? new EmbeddingService();
        $this->source_indexer = $source_indexer ?? new KnowledgeSourceIndexer($this->index, null, $this->embeddings);
    }

    /**
     * Mark the local retrieval cache stale when indexable content changes.
     */
    public static function mark_stale(mixed ...$unused): void {
        update_option('awpt_knowledge_stale', '1', false);
    }

    /**
     * Register content hooks that should invalidate the local retrieval cache.
     */
    public static function register_content_hooks(): void {
        foreach ([
            'save_post',
            'deleted_post',
            'trashed_post',
            'untrashed_post',
            'add_attachment',
            'edit_attachment',
            'delete_attachment',
            'switch_theme',
        ] as $hook) {
            add_action($hook, [self::class, 'mark_stale']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuild(): array {
        Installer::create_tables();

        $now = current_time('mysql');
        $sources = array_merge(
            new KnowledgeRepository()->list_sources(),
            new KnowledgeRepository()->list_site_content_sources(),
            new FilesystemSourceReader()->list_sources(),
        );

        $seen_source_ids = [];
        $indexed = 0;
        $chunks = 0;
        $skipped = 0;
        $embedded = 0;

        foreach ($sources as $source) {
            $result = $this->source_indexer->index($source, $now);

            if (null === $result) {
                continue;
            }

            $seen_source_ids[] = $result['source_id'];

            if ('skipped' === $result['status']) {
                ++$skipped;
                continue;
            }

            ++$indexed;
            $chunks += $result['chunks'];
            $embedded += $result['embedded'];
        }

        $this->index->delete_sources_not_in($seen_source_ids);

        update_option('awpt_knowledge_last_indexed_at', $now, false);
        update_option('awpt_knowledge_last_error', '', false);
        update_option('awpt_knowledge_stale', '0', false);

        return [
            'indexed_sources' => $indexed,
            'indexed_chunks' => $chunks,
            'embedded_chunks' => $embedded,
            'skipped_unchanged' => $skipped,
            'indexed_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array {
        return new KnowledgeIndexerStatus($this->index, $this->embeddings)->build();
    }
}
