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
    private const PROGRESS_OPTION = 'awpt_knowledge_rebuild_progress';
    private const COUNTS_OPTION = 'awpt_knowledge_index_counts';
    private const REBUILD_LOCK_OPTION = 'awpt_knowledge_rebuild_lock';
    private const REBUILD_LOCK_TTL = 900;

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
        if (!self::acquire_rebuild_lock()) {
            return ['in_progress' => true];
        }

        try {
            return $this->rebuild_unlocked();
        } finally {
            delete_option(self::REBUILD_LOCK_OPTION);
        }
    }

    /** @return array<string, mixed> */
    private function rebuild_unlocked(): array {
        Installer::create_tables();
        $this->embeddings->clear_last_error();

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

        self::update_progress('indexing', [
            'processed_sources' => 0,
            'total_sources' => count($sources),
            'indexed_sources' => $indexed,
            'indexed_chunks' => $chunks,
            'embedded_chunks' => $embedded,
        ]);

        foreach ($sources as $source) {
            $result = $this->source_indexer->index($source, $now);

            if (null === $result) {
                self::advance_progress($indexed, $chunks, $embedded);
                continue;
            }

            $seen_source_ids[] = $result['source_id'];

            if ('skipped' === $result['status']) {
                ++$skipped;
                self::advance_progress($indexed, $chunks, $embedded);
                continue;
            }

            ++$indexed;
            $chunks += $result['chunks'];
            $embedded += $result['embedded'];
            self::advance_progress($indexed, $chunks, $embedded);
        }

        $this->index->delete_sources_not_in($seen_source_ids);

        update_option('awpt_knowledge_last_indexed_at', $now, false);
        update_option('awpt_knowledge_last_error', '', false);
        update_option('awpt_knowledge_stale', '0', false);
        update_option(
            self::COUNTS_OPTION,
            [
                'chunk_count' => $chunks,
                'embedded_chunks' => $embedded,
            ],
            false,
        );
        self::update_progress('idle', [
            'processed_sources' => count($sources),
            'total_sources' => count($sources),
            'indexed_sources' => $indexed,
            'indexed_chunks' => $chunks,
            'embedded_chunks' => $embedded,
        ]);

        return [
            'indexed_sources' => $indexed,
            'indexed_chunks' => $chunks,
            'embedded_chunks' => $embedded,
            'embedding_error' => $this->embeddings->last_error(),
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

    /** @return array{state: string, processed_sources: int, total_sources: int, indexed_sources: int, indexed_chunks: int, embedded_chunks: int} */
    public static function progress(): array {
        $raw = get_option(self::PROGRESS_OPTION, []);
        $progress = is_array($raw) ? $raw : [];

        return [
            'state' => in_array($progress['state'] ?? null, ['indexing', 'idle', 'failed'], true)
                ? $progress['state']
                : 'idle',
            'processed_sources' => max(0, (int) ($progress['processed_sources'] ?? 0)),
            'total_sources' => max(0, (int) ($progress['total_sources'] ?? 0)),
            'indexed_sources' => max(0, (int) ($progress['indexed_sources'] ?? 0)),
            'indexed_chunks' => max(0, (int) ($progress['indexed_chunks'] ?? 0)),
            'embedded_chunks' => max(0, (int) ($progress['embedded_chunks'] ?? 0)),
        ];
    }

    /** @return array{chunk_count: int, embedded_chunks: int} */
    public static function cached_counts(): array {
        $raw = get_option(self::COUNTS_OPTION, []);
        $counts = is_array($raw) ? $raw : [];

        return [
            'chunk_count' => max(0, (int) ($counts['chunk_count'] ?? 0)),
            'embedded_chunks' => max(0, (int) ($counts['embedded_chunks'] ?? 0)),
        ];
    }

    public static function mark_rebuild_failed(): void {
        $progress = self::progress();
        self::update_progress('failed', [
            'processed_sources' => $progress['processed_sources'],
            'total_sources' => $progress['total_sources'],
            'indexed_sources' => $progress['indexed_sources'],
            'indexed_chunks' => $progress['indexed_chunks'],
            'embedded_chunks' => $progress['embedded_chunks'],
        ]);
    }

    /**
     * Whether a Knowledge rebuild is currently protected by the single-flight lock.
     *
     * Searches must stay non-blocking while a rebuild owns the index tables: chat can
     * still answer without retrieval, whereas waiting on a full-text query leaves the
     * entire terminal stuck in its sending state.
     */
    public static function rebuild_in_progress(): bool {
        $started_at = (int) get_option(self::REBUILD_LOCK_OPTION, 0);

        return $started_at > 0 && ($started_at + self::REBUILD_LOCK_TTL) >= time();
    }

    /**
     * Whether it is safe to query the local retrieval tables during a chat request.
     */
    public static function retrieval_is_available(): bool {
        if (self::rebuild_in_progress()) {
            return false;
        }

        // A stale index with no known completed chunk count is either still being
        // built or recovering from an interrupted build. Avoid a synchronous
        // full-text query until the index has a usable completed snapshot.
        if ('1' === (string) get_option('awpt_knowledge_stale', '0') && 0 === self::cached_counts()['chunk_count']) {
            return false;
        }

        return true;
    }

    private static function advance_progress(int $indexed, int $chunks, int $embedded): void {
        $progress = self::progress();
        self::update_progress('indexing', [
            'processed_sources' => min($progress['processed_sources'] + 1, $progress['total_sources']),
            'total_sources' => $progress['total_sources'],
            'indexed_sources' => $indexed,
            'indexed_chunks' => $chunks,
            'embedded_chunks' => $embedded,
        ]);
    }

    /** @param array<string, int> $counts */
    private static function update_progress(string $state, array $counts): void {
        update_option(
            self::PROGRESS_OPTION,
            [
                'state' => $state,
                'processed_sources' => max(0, (int) ($counts['processed_sources'] ?? 0)),
                'total_sources' => max(0, (int) ($counts['total_sources'] ?? 0)),
                'indexed_sources' => max(0, (int) ($counts['indexed_sources'] ?? 0)),
                'indexed_chunks' => max(0, (int) ($counts['indexed_chunks'] ?? 0)),
                'embedded_chunks' => max(0, (int) ($counts['embedded_chunks'] ?? 0)),
            ],
            false,
        );
    }

    private static function acquire_rebuild_lock(): bool {
        $now = time();

        if (add_option(self::REBUILD_LOCK_OPTION, $now, '', false)) {
            return true;
        }

        $started_at = (int) get_option(self::REBUILD_LOCK_OPTION, 0);

        if ($started_at > 0 && ($started_at + self::REBUILD_LOCK_TTL) >= $now) {
            return false;
        }

        delete_option(self::REBUILD_LOCK_OPTION);

        return add_option(self::REBUILD_LOCK_OPTION, $now, '', false);
    }
}
