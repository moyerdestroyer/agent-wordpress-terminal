<?php

/**
 * Optional embedding rerank over the strongest deterministic candidates.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Knowledge\EmbeddingService;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class PatternSemanticReranker {
    private const CANDIDATE_LIMIT = 16;

    private const CACHE_SECONDS = 300;

    private EmbeddingService $embeddings;

    public function __construct(?EmbeddingService $embeddings = null) {
        $this->embeddings = $embeddings ?? new EmbeddingService();
    }

    /**
     * @param list<array<string, mixed>> $ranked
     * @return array{ranked: list<array<string, mixed>>, mode: string}
     */
    public function rerank(array $ranked, string $intent): array {
        if (!$this->embeddings->is_enabled() || '' === trim($intent) || [] === $ranked) {
            return ['ranked' => $ranked, 'mode' => 'deterministic'];
        }

        $candidates = array_slice($ranked, 0, self::CANDIDATE_LIMIT);
        $fingerprint = hash(
            'sha256',
            wp_json_encode(array_map(static fn(array $row): array => [
                'name' => (string) ($row['pattern']['name'] ?? ''),
                'hash' => (string) ($row['pattern']['content_hash'] ?? ''),
            ], $candidates)) ?: '',
        );
        $cache_key =
            'awpt_pattern_rank_'
            . md5($this->embeddings->profile() . '|' . mb_strtolower(trim($intent)) . '|' . $fingerprint);
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return ['ranked' => $this->apply_scores($ranked, $cached), 'mode' => 'hybrid-cache'];
        }

        $texts = [$intent];

        foreach ($candidates as $row) {
            $pattern = is_array($row['pattern'] ?? null) ? $row['pattern'] : [];
            $domain = is_array($pattern['domain'] ?? null) ? $pattern['domain'] : [];
            $texts[] = implode(' ', [
                (string) ($pattern['title'] ?? ''),
                (string) ($pattern['description'] ?? ''),
                (string) ($domain['role'] ?? ''),
                (string) ($domain['summary'] ?? ''),
                implode(' ', ArrayKey::list_of_strings($domain['intents'] ?? null)),
                implode(' ', ArrayKey::list_of_strings($domain['use_when'] ?? null)),
                implode(' ', ArrayKey::list_of_strings($domain['search_terms'] ?? null)),
            ]);
        }

        $vectors = $this->embeddings->embed_many($texts);
        $query = $vectors[0] ?? null;

        if (!is_array($query) || [] === $query) {
            return ['ranked' => $ranked, 'mode' => 'deterministic'];
        }

        $scores = [];

        foreach ($candidates as $index => $row) {
            $vector = $vectors[$index + 1] ?? null;

            if (!is_array($vector) || [] === $vector) {
                continue;
            }

            $name = (string) ($row['pattern']['name'] ?? '');

            if ('' !== $name) {
                $scores[$name] = $this->embeddings->cosine_similarity($query, $vector);
            }
        }

        if ([] === $scores) {
            return ['ranked' => $ranked, 'mode' => 'deterministic'];
        }

        set_transient($cache_key, $scores, self::CACHE_SECONDS);

        return ['ranked' => $this->apply_scores($ranked, $scores), 'mode' => 'hybrid'];
    }

    /**
     * @param list<array<string, mixed>> $ranked
     * @param array<array-key, mixed> $scores
     * @return list<array<string, mixed>>
     */
    private function apply_scores(array $ranked, array $scores): array {
        foreach ($ranked as &$row) {
            $name = (string) ($row['pattern']['name'] ?? '');

            if (!is_numeric($scores[$name] ?? null)) {
                continue;
            }

            $similarity = max(-1.0, min(1.0, (float) $scores[$name]));
            $row['semantic_score'] = round($similarity, 4);
            $row['score'] = (int) ($row['score'] ?? 0) + (int) round(max(0.0, $similarity) * 50);
            $row['rationale'] = trim(
                (string) ($row['rationale'] ?? '') . ' '
                    . sprintf(__('Semantic fit %.2f.', 'agent-wordpress-terminal'), $similarity),
            );
        }
        unset($row);

        return $ranked;
    }
}
