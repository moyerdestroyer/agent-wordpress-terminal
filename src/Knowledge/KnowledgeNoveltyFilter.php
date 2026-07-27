<?php

/**
 * Novelty-aware Knowledge result selection.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeNoveltyFilter {
    /**
     * @param list<array<string, mixed>> $candidates
     * @param list<string>               $seen_chunk_ids
     * @return array{
     *     items: list<array<string, mixed>>,
     *     known_matches: list<array<array-key, mixed>>,
     *     novel_count: int,
     *     reused_count: int,
     *     exhausted: bool
     * }
     */
    public function select(array $candidates, array $seen_chunk_ids, int $limit): array {
        $limit = max(1, min(12, $limit));
        $seen = array_fill_keys(array_filter(array_map('strval', $seen_chunk_ids)), true);
        $novel = [];
        $known = [];

        foreach ($candidates as $candidate) {
            $chunk_id = (string) ($candidate['chunk_id'] ?? '');

            if ('' !== $chunk_id && array_key_exists($chunk_id, $seen)) {
                if (count($known) < 4) {
                    $known[] = $this->compact($candidate);
                }
                continue;
            }

            if (count($novel) < $limit) {
                $novel[] = $candidate;
            }
        }

        return [
            'items' => $novel,
            'known_matches' => $known,
            'novel_count' => count($novel),
            'reused_count' => count($known),
            'exhausted' => [] === $novel && [] !== $known,
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function compact(array $item): array {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        return [
            'chunk_id' => (string) ($item['chunk_id'] ?? ''),
            'source_id' => (string) ($item['source_id'] ?? ''),
            'source_kind' => (string) ($item['source_kind'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'heading_path' => (string) ($metadata['heading_path'] ?? ''),
        ];
    }
}
