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

defined('ABSPATH') || exit();

/**
 * Builds AWPT's local retrieval cache from Knowledge and safe read-only sources.
 */
final class KnowledgeIndexer
{
    private const CHUNK_SIZE = 3000;
    private const CHUNK_OVERLAP = 250;

    private KnowledgeIndexRepository $index;

    public function __construct(?KnowledgeIndexRepository $index = null)
    {
        $this->index = $index ?? new KnowledgeIndexRepository();
    }

    /**
     * Mark the local retrieval cache stale when indexable content changes.
     */
    public static function mark_stale(mixed ...$unused): void
    {
        update_option('awpt_knowledge_stale', '1', false);
    }

    /**
     * Register content hooks that should invalidate the local retrieval cache.
     */
    public static function register_content_hooks(): void
    {
        foreach ([
            'save_post',
            'deleted_post',
            'trashed_post',
            'untrashed_post',
            'add_attachment',
            'edit_attachment',
            'delete_attachment',
        ] as $hook) {
            add_action($hook, [self::class, 'mark_stale']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuild(): array
    {
        Installer::create_tables();

        $now = current_time('mysql');
        $sources = array_merge(
            (new KnowledgeRepository())->list_sources(),
            (new KnowledgeRepository())->list_site_content_sources(),
            (new FilesystemSourceReader())->list_sources(),
        );

        $this->index->clear_index();

        $indexed = 0;
        $chunks = 0;

        foreach ($sources as $source) {
            $content = trim(wp_strip_all_tags((string) ($source['content'] ?? '')));

            if ('' === $content) {
                continue;
            }

            $index_id = $this->index->insert_source($source, $content, $now);

            if ($index_id <= 0) {
                continue;
            }

            $indexed++;

            foreach ($this->chunk_text($content) as $chunk_index => $chunk_text) {
                $this->index->insert_chunk($index_id, $chunk_index, $chunk_text, $now);
                $chunks++;
            }
        }

        update_option('awpt_knowledge_last_indexed_at', $now, false);
        update_option('awpt_knowledge_last_error', '', false);
        update_option('awpt_knowledge_stale', '0', false);

        return [
            'indexed_sources' => $indexed,
            'indexed_chunks' => $chunks,
            'indexed_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        Installer::create_tables();

        $source_count = $this->index->count_sources();
        $chunk_count = $this->index->count_chunks();

        return [
            'source_count' => $source_count,
            'chunk_count' => $chunk_count,
            'stale' => '1' === (string) get_option('awpt_knowledge_stale', '0'),
            'needs_rebuild' =>
                0 === $source_count || 0 === $chunk_count || '1' === (string) get_option('awpt_knowledge_stale', '0'),
            'last_indexed_at' => (string) get_option('awpt_knowledge_last_indexed_at', ''),
            'last_error' => (string) get_option('awpt_knowledge_last_error', ''),
            'embedding' => [
                'available' => false,
                'provider' => '',
                'model' => '',
                'label' => __(
                    'Keyword retrieval active; embeddings are not configured yet.',
                    'agent-wordpress-terminal',
                ),
            ],
            'filesystem' => [
                'allowed_roots' => (new FilesystemSourceReader())->allowed_roots(),
                'max_file_size' => (int) get_option('awpt_knowledge_max_file_size', 2_097_152),
            ],
            'repository' => (new KnowledgeRepository())->status(),
        ];
    }

    /**
     * @return list<string>
     */
    private function chunk_text(string $content): array
    {
        $normalized = preg_replace('/\s+/', ' ', $content);
        $content = trim(is_string($normalized) ? $normalized : $content);
        $length = mb_strlen($content, 'UTF-8');

        if ($length <= self::CHUNK_SIZE) {
            return [$content];
        }

        $chunks = [];
        $offset = 0;

        while ($offset < $length) {
            $chunk = mb_substr($content, $offset, self::CHUNK_SIZE, 'UTF-8');

            if ('' !== trim($chunk)) {
                $chunks[] = trim($chunk);
            }

            $offset += self::CHUNK_SIZE - self::CHUNK_OVERLAP;
        }

        return $chunks;
    }
}
