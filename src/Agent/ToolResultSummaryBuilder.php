<?php

/**
 * Builds compact summaries for truncated tool outputs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Produces a structured summary when full tool output exceeds size limits.
 */
final class ToolResultSummaryBuilder {
    private ToolResultFieldShrinker $shrinker;

    public function __construct(?ToolResultFieldShrinker $shrinker = null) {
        $this->shrinker = $shrinker ?? new ToolResultFieldShrinker();
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public function build(string $tool, array $output, int $original_bytes): array {
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
            $summary['plain_text'] = $this->shrinker->clip_string((string) ($output['plain_text'] ?? ''), 1_500);
        }

        if ('awpt/list-content' === $tool) {
            $summary['items'] = $this->shrinker->clip_array_items($output['items'] ?? [], 10);
        }

        return $summary;
    }
}
