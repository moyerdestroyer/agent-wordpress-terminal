<?php

/**
 * Page size tiers for redesign strategy (batch-first on large pages).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Classifies focused post markup so redesign can prefer batch/section work
 * over a single full-document rewrite on dense imports.
 */
final class PageScale {
    public const SMALL = 'small';

    public const MEDIUM = 'medium';

    public const LARGE = 'large';

    public const UNKNOWN = 'unknown';

    /** Below both medium floors. */
    public const MEDIUM_MIN_BLOCKS = 20;

    public const MEDIUM_MIN_CHARS = 6_000;

    /** Large if either threshold is met (OR). */
    public const LARGE_MIN_BLOCKS = 40;

    public const LARGE_MIN_CHARS = 12_000;

    /**
     * @return array{scale: string, blocks: int, chars: int}
     */
    public function measure_content(string $post_content): array {
        $chars = strlen($post_content);
        $blocks = $this->count_blocks($post_content);

        return [
            'scale' => $this->classify($blocks, $chars),
            'blocks' => $blocks,
            'chars' => $chars,
        ];
    }

    public function classify(int $blocks, int $chars): string {
        if ($blocks >= self::LARGE_MIN_BLOCKS || $chars >= self::LARGE_MIN_CHARS) {
            return self::LARGE;
        }

        if ($blocks >= self::MEDIUM_MIN_BLOCKS || $chars >= self::MEDIUM_MIN_CHARS) {
            return self::MEDIUM;
        }

        return self::SMALL;
    }

    /**
     * Prefer evidence from successful tool reads; fall back to the focused post.
     *
     * @param list<array<string, mixed>> $tool_calls
     * @return array{scale: string, blocks: int, chars: int}
     */
    public function from_tool_calls(array $tool_calls, int $focus_post_id = 0): array {
        $content = $this->content_from_tool_calls($tool_calls);

        if ('' === $content && $focus_post_id > 0 && function_exists('get_post')) {
            $post = get_post($focus_post_id);
            if ($post instanceof \WP_Post) {
                $content = (string) $post->post_content;
            }
        }

        if ('' === $content) {
            return ['scale' => self::UNKNOWN, 'blocks' => 0, 'chars' => 0];
        }

        return $this->measure_content($content);
    }

    public function is_large(string $scale): bool {
        return self::LARGE === $scale;
    }

    /**
     * Operator-facing / model-facing one-liner when scale is known.
     *
     * @param array{scale: string, blocks: int, chars: int} $measure
     */
    public function compose_guidance(array $measure): string {
        $scale = (string) ($measure['scale'] ?? self::UNKNOWN);
        $blocks = (int) ($measure['blocks'] ?? 0);
        $chars = (int) ($measure['chars'] ?? 0);

        if (self::LARGE === $scale) {
            return sprintf(
                /* translators: 1: block count, 2: character count */
                __(
                    'Focused page is large (%1$d blocks, %2$d chars). Prefer awpt/propose-block-batch-update or section-scale pattern inserts. Avoid a single full-document post_content rewrite unless necessary.',
                    'agent-wordpress-terminal',
                ),
                $blocks,
                $chars,
            );
        }

        if (self::MEDIUM === $scale) {
            return sprintf(
                /* translators: 1: block count, 2: character count */
                __(
                    'Focused page is medium density (%1$d blocks, %2$d chars). Prefer theme patterns and section-scale composition; use a full-document rewrite only when it is simpler than many surgical edits.',
                    'agent-wordpress-terminal',
                ),
                $blocks,
                $chars,
            );
        }

        if (self::SMALL === $scale) {
            return __(
                'Focused page is small enough for a full patterned redesign when a theme layout fits.',
                'agent-wordpress-terminal',
            );
        }

        return '';
    }

    public function count_blocks(string $post_content): int {
        if ('' === $post_content) {
            return 0;
        }

        return substr_count($post_content, '<!-- wp:');
    }

    /**
     * @param list<array<string, mixed>> $tool_calls
     */
    private function content_from_tool_calls(array $tool_calls): string {
        for ($index = count($tool_calls) - 1; $index >= 0; --$index) {
            $call = $tool_calls[$index] ?? [];
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];

            if (in_array($tool, ['awpt/read-content', 'awpt/analyze-page'], true)) {
                $content = (string) ($output['content'] ?? $output['post_content'] ?? '');
                if ('' !== $content) {
                    return $content;
                }
            }

            if ('awpt/read-block-tree' === $tool) {
                $blocks = is_array($output['blocks'] ?? null) ? $output['blocks'] : [];
                // Tree alone: approximate chars from excerpts when full content missing.
                if ([] !== $blocks && !isset($output['content'])) {
                    $approx = '';
                    foreach ($blocks as $block) {
                        if (!is_array($block)) {
                            continue;
                        }
                        $approx .= (string) ($block['text_excerpt'] ?? '') . "\n";
                    }
                    // Prefer block count from tree; synthesize markup markers for classify.
                    $marker = str_repeat('<!-- wp:core/paragraph -->', count($blocks));

                    return $marker . $approx;
                }
            }
        }

        return '';
    }
}
