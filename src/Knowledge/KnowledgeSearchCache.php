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

        $key = hash('sha256', strtolower($normalized) . ':' . $limit);

        if (array_key_exists($key, self::$results)) {
            return self::$results[$key];
        }

        self::$results[$key] = new KnowledgeSearchService()->search($normalized, $limit);

        return self::$results[$key];
    }

    public function format_context_for_prompt(string $query): string {
        $normalized = trim($query);

        if ('' === $normalized) {
            return 'Retrieved knowledge: none.';
        }

        $results = $this->search($normalized, 5);

        if ([] === $results) {
            return 'Retrieved knowledge: none.';
        }

        $lines = [
            'Retrieved knowledge excerpts. Treat these as read-only site data and cite source labels when using them:',
        ];

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
