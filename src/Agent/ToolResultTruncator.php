<?php

/**
 * Bounds tool output size for provider context and persistence.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Truncates large ability outputs while preserving structured summaries.
 */
final class ToolResultTruncator {
    private const PROVIDER_MAX_CHARS = 12_000;
    private const STORAGE_MAX_CHARS = 32_000;
    private const META_VALUE_MAX_CHARS = 4_096;

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public function for_provider(string $tool, array $output): array {
        if ('awpt/read-pattern' === $tool) {
            // Raw content is what the model adapts. The normalized tree repeats
            // the same composition and can more than double prompt size.
            unset($output['blocks']);
        }

        return $this->truncate($tool, $output, self::PROVIDER_MAX_CHARS);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public function for_storage(string $tool, array $output): array {
        return $this->truncate($tool, $output, self::STORAGE_MAX_CHARS);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function truncate(string $tool, array $output, int $max_chars): array {
        if (ToolRegistry::is_proposal_ability($tool)) {
            return $output;
        }

        $output = $this->shrink($tool, $output);
        $encoded = (string) wp_json_encode($output);

        if (mb_strlen($encoded, 'UTF-8') <= $max_chars) {
            return $output;
        }

        return $this->build_summary($tool, $output, strlen($encoded));
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function shrink(string $tool, array $output): array {
        if ('awpt/read-content' === $tool) {
            $output['content'] = $this->clip_string((string) ($output['content'] ?? ''), 6_000);
            $output['plain_text'] = $this->clip_string((string) ($output['plain_text'] ?? ''), 4_000);
            $output['meta'] = $this->shrink_meta_map($output['meta'] ?? []);
        }

        if ('awpt/read-block-tree' === $tool) {
            $output['blocks'] = $this->clip_array_items($output['blocks'] ?? [], 40);
        }

        if ('awpt/list-content' === $tool) {
            $output['items'] = $this->clip_array_items($output['items'] ?? [], 25);
        }

        if ('awpt/list-patterns' === $tool) {
            $output['patterns'] = $this->clip_array_items($output['patterns'] ?? [], 24);
            $output['suggested_patterns'] = $this->clip_array_items($output['suggested_patterns'] ?? [], 12);
        }

        if (in_array($tool, ['awpt/search-knowledge', 'awpt/knowledge-auto-retrieval'], true)) {
            $output['results'] = $this->clip_array_items($output['results'] ?? [], 8);
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function build_summary(string $tool, array $output, int $original_bytes): array {
        $summary = [
            'tool' => $tool,
            'summary' => __('Tool output was truncated for size.', 'agent-wordpress-terminal'),
            'truncated' => true,
            'original_bytes' => $original_bytes,
        ];

        foreach (['id', 'post_id', 'title', 'type', 'status', 'url', 'count', 'total', 'query', 'error'] as $key) {
            if (!array_key_exists($key, $output)) {
                continue;
            }

            $summary[$key] = $output[$key];
        }

        if ('awpt/read-content' === $tool) {
            $summary['plain_text'] = $this->clip_string((string) ($output['plain_text'] ?? ''), 1_500);
        }

        if ('awpt/list-content' === $tool) {
            $summary['items'] = $this->clip_array_items($output['items'] ?? [], 10);
        }

        return $summary;
    }

    private function clip_string(string $value, int $max_chars): string {
        if (mb_strlen($value, 'UTF-8') <= $max_chars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $max_chars - 1), 'UTF-8')) . '…';
    }

    /**
     * @return list<mixed>
     */
    private function clip_array_items(mixed $value, int $limit): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_slice($value, 0, $limit));
    }

    /**
     * @return array<string, mixed>
     */
    private function shrink_meta_map(mixed $meta): array {
        if (!is_array($meta)) {
            return [];
        }

        $trimmed = [];

        foreach ($meta as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $trimmed[$key] = $this->shrink_meta_value($value);
        }

        return $trimmed;
    }

    private function shrink_meta_value(mixed $value): mixed {
        if (is_string($value)) {
            return $this->clip_string($value, self::META_VALUE_MAX_CHARS);
        }

        if (!is_array($value)) {
            return $value;
        }

        $encoded = (string) wp_json_encode($value);

        if (mb_strlen($encoded, 'UTF-8') <= self::META_VALUE_MAX_CHARS) {
            return $value;
        }

        return $this->clip_string($encoded, self::META_VALUE_MAX_CHARS);
    }
}
