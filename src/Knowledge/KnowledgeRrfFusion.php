<?php

/**
 * Reciprocal Rank Fusion for hybrid Knowledge retrieval.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Merges keyword and embedding ranked lists.
 */
final class KnowledgeRrfFusion {
    private const K = 60;

    /**
     * @param list<array<string, mixed>> $keyword
     * @param list<array<string, mixed>> $semantic
     * @return list<array<string, mixed>>
     */
    public function fuse(array $keyword, array $semantic, int $limit): array {
        $scores = [];
        $docs = [];

        foreach ([$keyword, $semantic] as $list) {
            foreach (array_values($list) as $rank => $item) {
                $key = $this->result_key($item);
                $scores[$key] = ($scores[$key] ?? 0.0) + (1.0 / (self::K + $rank + 1));
                $docs[$key] = $this->merge_doc($docs[$key] ?? null, $item);
            }
        }

        arsort($scores, SORT_NUMERIC);
        $fused = [];

        foreach ($scores as $key => $rrf_score) {
            $doc = $docs[$key];
            $doc['score'] = round($rrf_score, 6);
            $fused[] = $doc;

            if (count($fused) >= $limit) {
                break;
            }
        }

        return $fused;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed>      $incoming
     * @return array<string, mixed>
     */
    private function merge_doc(?array $existing, array $incoming): array {
        if (null === $existing) {
            return $incoming;
        }

        $existing_match = (string) ($existing['match'] ?? '');
        $incoming_match = (string) ($incoming['match'] ?? '');

        if (
            'keyword' === $existing_match && 'embedding' === $incoming_match
            || 'embedding' === $existing_match && 'keyword' === $incoming_match
        ) {
            $existing['match'] = 'hybrid';
        }

        return $existing;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function result_key(array $item): string {
        $id = (int) ($item['id'] ?? 0);

        if ($id > 0) {
            return 'chunk:' . $id;
        }

        return 'source:'
        . (string) ($item['source_id'] ?? '')
        . ':'
        . mb_substr((string) ($item['excerpt'] ?? ''), 0, 40);
    }
}
