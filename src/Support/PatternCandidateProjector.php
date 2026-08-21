<?php

/**
 * Compact provider-facing pattern selection evidence.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

final class PatternCandidateProjector {
    /**
     * @param list<array<string, mixed>> $recommendations
     * @return list<array<string, mixed>>
     */
    public function many(array $recommendations, int $max = 4, int $max_chars = 4_500): array {
        /** @var list<array<string, mixed>> $projected */
        $projected = [];
        $max = max(1, min(12, $max));
        $max_chars = max(1_000, $max_chars);

        foreach (array_slice($recommendations, 0, $max) as $recommendation) {
            $candidate = $this->one($recommendation);

            if ('' === (string) ($candidate['name'] ?? '')) {
                continue;
            }

            $projected[] = ArrayKey::string_map($candidate);
            $encoded = wp_json_encode($projected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (count($projected) > 1 && is_string($encoded) && mb_strlen($encoded, 'UTF-8') > $max_chars) {
                array_pop($projected);
                break;
            }
        }

        return $projected;
    }

    /** @param array<array-key, mixed> $recommendation @return array<string, mixed> */
    public function one(array $recommendation): array {
        $recommendation = ArrayKey::string_map($recommendation);
        $pattern = ArrayKey::as_map($recommendation['pattern'] ?? null);
        $domain = ArrayKey::as_map($pattern['domain'] ?? null);

        return array_filter(
            [
                'name' => sanitize_text_field((string) ($pattern['name'] ?? '')),
                'title' => sanitize_text_field((string) ($pattern['title'] ?? '')),
                'role' => sanitize_key((string) ($domain['role'] ?? '')),
                'composition_scope' => sanitize_key((string) ($pattern['composition_scope'] ?? '')),
                'summary' => $this->clip((string) ($domain['summary'] ?? $pattern['description'] ?? ''), 320),
                'use_when' => $this->statements($domain['use_when'] ?? null),
                'avoid_when' => $this->statements($domain['avoid_when'] ?? null),
                'dynamic_content' => ArrayKey::rest_bool($domain['dynamic_content'] ?? false),
                'post_types' => array_slice(ArrayKey::list_of_strings($domain['post_types'] ?? null), 0, 6),
                'composed' => array_slice(ArrayKey::list_of_strings($domain['composed'] ?? null), 0, 8),
                'score' => (int) ($recommendation['score'] ?? 0),
                'matched_terms' => array_slice(
                    ArrayKey::list_of_strings($recommendation['matched_terms'] ?? null),
                    0,
                    12,
                ),
                'rationale' => $this->clip((string) ($recommendation['rationale'] ?? ''), 500),
            ],
            static fn(mixed $value): bool => (
                !(is_string($value) && '' === $value) && !(is_array($value) && [] === $value)
            ),
        );
    }

    /** @return list<string> */
    private function statements(mixed $value): array {
        return array_values(array_map(
            fn(string $item): string => $this->clip($item, 320),
            array_slice(ArrayKey::list_of_strings($value), 0, 2),
        ));
    }

    private function clip(string $value, int $max): string {
        $value = sanitize_textarea_field($value);

        return mb_strlen($value, 'UTF-8') <= $max ? $value : rtrim(mb_substr($value, 0, $max - 1, 'UTF-8')) . '…';
    }
}
