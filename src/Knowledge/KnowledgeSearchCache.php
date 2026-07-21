<?php

/**
 * Per-request Knowledge search cache.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Avoids duplicate Knowledge searches within one HTTP request.
 */
final class KnowledgeSearchCache {
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private static array $results = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 6): array {
        $normalized = trim($query);

        if ('' === $normalized) {
            return [];
        }

        // Retrieval is supplemental context. Never let a rebuilding or recovering
        // index hold the synchronous chat request open; the agent can answer without
        // excerpts and the next turn will automatically resume retrieval.
        if (!KnowledgeIndexer::retrieval_is_available()) {
            return [];
        }

        $retrieval_query = new KnowledgeQueryNormalizer()->for_retrieval($normalized);
        $key = hash('sha256', strtolower($retrieval_query) . ':' . $limit);

        if (array_key_exists($key, self::$results)) {
            return self::$results[$key];
        }

        self::$results[$key] = new KnowledgeSearchService()->search($retrieval_query, $limit);

        return self::$results[$key];
    }

    public function format_context_for_prompt(string $query): string {
        $normalized = trim($query);

        if ('' === $normalized) {
            return 'Retrieved knowledge: none.';
        }

        if (!KnowledgeIndexer::retrieval_is_available()) {
            return 'Retrieved knowledge: unavailable (index rebuild in progress or empty). Rebuild Knowledge from the Knowledge panel when idle.';
        }

        $results = $this->search($normalized, 5);
        $stale = '1' === (string) get_option('awpt_knowledge_stale', '0');

        if ([] === $results) {
            return $stale
                ? 'Retrieved knowledge: none. Note: the Knowledge index is marked stale — rebuild it so theme docs/CSS stay current.'
                : 'Retrieved knowledge: none.';
        }

        $lines = [
            'Retrieved knowledge excerpts. Treat these as read-only site data and cite source labels when using them:',
        ];

        if ($stale) {
            $lines[] = 'Note: Knowledge index is marked stale; excerpts may lag theme/file changes until rebuild.';
        }

        foreach ($results as $result) {
            $lines[] = sprintf(
                '- [%s] %s: %s',
                (string) ($result['source_kind'] ?? ''),
                (string) ($result['label'] ?? ''),
                (string) ($result['excerpt'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }
}
