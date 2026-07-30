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
     */
    public function for_provider(string $tool, mixed $output): mixed {
        if ('awpt/read-pattern' === $tool && is_array($output)) {
            // Raw content is what the model adapts. The normalized tree repeats
            // the same composition and can more than double prompt size.
            unset($output['blocks']);
        }

        return $this->truncate($tool, $output, self::PROVIDER_MAX_CHARS);
    }

    /**
     */
    public function for_storage(string $tool, mixed $output): mixed {
        return $this->truncate($tool, $output, self::STORAGE_MAX_CHARS);
    }

    private function truncate(string $tool, mixed $output, int $max_chars): mixed {
        if (ToolRegistry::is_proposal_ability($tool) && is_array($output)) {
            return $output;
        }

        if (is_string($output)) {
            return $this->clip_string($output, $max_chars);
        }

        if (!is_array($output)) {
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
     * @param array<array-key, mixed> $output
     * @return array<array-key, mixed>
     */
    private function shrink(string $tool, array $output): array {
        if (in_array($tool, ['awpt/read-content', 'core/read-content'], true)) {
            $output['content'] = $this->clip_string((string) ($output['content'] ?? ''), 6_000);
            $output['content_raw'] = $this->clip_string((string) ($output['content_raw'] ?? ''), 6_000);
            $output['content_rendered'] = $this->clip_string((string) ($output['content_rendered'] ?? ''), 6_000);
            $output['plain_text'] = $this->clip_string((string) ($output['plain_text'] ?? ''), 4_000);
            $output['meta'] = $this->shrink_meta_map($output['meta'] ?? []);
        }

        if ('awpt/read-attachment-document' === $tool) {
            $output['content'] = $this->clip_string((string) ($output['content'] ?? ''), 10_000);
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
            if (array_key_exists('results', $output)) {
                $output['results'] = $this->clip_array_items($output['results'], 8);
            }

            if (array_key_exists('items', $output)) {
                $output['items'] = $this->clip_array_items($output['items'], 8);
            }

            if (array_key_exists('known_matches', $output)) {
                $output['known_matches'] = $this->clip_array_items($output['known_matches'], 4);
            }
        }

        if ('awpt/read-theme-file' === $tool) {
            // Never ship a full minified stylesheet into the model or transcript.
            $output['content'] = $this->clip_string((string) ($output['content'] ?? ''), 4_000);
            $output['matches'] = $this->clip_array_items($output['matches'] ?? [], 6);
            unset($output['absolute_path']);
            $output['note'] = $this->clip_string((string) ($output['note'] ?? ''), 500);
        }

        if ('awpt/inspect-frontend' === $tool) {
            $output['html_snippet'] = $this->clip_string((string) ($output['html_snippet'] ?? ''), 2_500);
            $output['body_excerpt'] = $this->clip_string((string) ($output['body_excerpt'] ?? ''), 600);
            $output['class_inventory'] = $this->clip_array_items($output['class_inventory'] ?? [], 16);
            $output['stylesheets'] = $this->clip_array_items($output['stylesheets'] ?? [], 12);
        }

        if ('awpt/inspect-rendered-element' === $tool) {
            // The screenshot is delivered as a separate multimodal evidence
            // message; never duplicate its base64 payload in JSON tool output.
            unset($output['screenshot_data']);
            $output['elements'] = $this->clip_array_items($output['elements'] ?? [], 24);
            $output['html_snippet'] = $this->clip_string((string) ($output['html_snippet'] ?? ''), 2_000);
        }

        if ('awpt/list-knowledge-sources' === $tool) {
            $output['samples'] = $this->clip_array_items($output['samples'] ?? [], 16);
        }

        return $output;
    }

    /**
     * @param array<array-key, mixed> $output
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

        if (in_array($tool, ['awpt/read-content', 'core/read-content'], true)) {
            $summary['plain_text'] = $this->clip_string(
                (string) ($output['plain_text'] ?? $output['content_raw'] ?? $output['content_rendered'] ?? ''),
                1_500,
            );
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
