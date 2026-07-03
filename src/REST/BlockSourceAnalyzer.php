<?php

/**
 * Block source analyzer.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

defined('ABSPATH') || exit();

/**
 * Extracts compact source signals from block content.
 */
final class BlockSourceAnalyzer
{
    /**
     * Summarize parsed block structure for source scope.
     *
     * @return array{count: int, names: array<int, string>, reusable_refs: array<int, int>, template_part_refs: array<int, string>, detected_patterns: array<int, string>}
     */
    public function summarize(string $content): array
    {
        $blocks = function_exists('parse_blocks') ? call_user_func('parse_blocks', $content) : [];
        $summary = [
            'count' => 0,
            'names' => [],
            'reusable_refs' => [],
            'template_part_refs' => [],
            'detected_patterns' => [],
        ];

        $this->walk_blocks(is_array($blocks) ? $blocks : [], $summary);
        $summary['names'] = array_values(array_slice(array_unique($summary['names']), 0, 30));
        $summary['reusable_refs'] = array_values(array_slice(array_unique($summary['reusable_refs']), 0, 30));
        $summary['template_part_refs'] = array_values(array_slice(array_unique($summary['template_part_refs']), 0, 30));
        $summary['detected_patterns'] = $this->detect_block_patterns($summary['names']);

        return $summary;
    }

    /**
     * Walk parsed blocks recursively.
     *
     * @param array<array-key, mixed>                                                                                                                           $blocks Parsed blocks.
     * @param array{count: int, names: array<int, string>, reusable_refs: array<int, int>, template_part_refs: array<int, string>, detected_patterns: array<int, string>} $summary Summary accumulator.
     */
    private function walk_blocks(array $blocks, array &$summary): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $block_name = (string) ($block['blockName'] ?? '');

            if ('' !== $block_name) {
                ++$summary['count'];
                $summary['names'][] = $block_name;
            }

            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if ('core/block' === $block_name && array_key_exists('ref', $attrs)) {
                $summary['reusable_refs'][] = (int) $attrs['ref'];
            }

            if ('core/template-part' === $block_name && array_key_exists('slug', $attrs)) {
                $summary['template_part_refs'][] = (string) $attrs['slug'];
            }

            $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if ([] !== $inner_blocks) {
                $this->walk_blocks($inner_blocks, $summary);
            }
        }
    }

    /**
     * Detect high-level editorial/design patterns from block names.
     *
     * @param array<int, string> $block_names Unique block names.
     * @return array<int, string>
     */
    private function detect_block_patterns(array $block_names): array
    {
        $patterns = [];

        if (array_intersect($block_names, ['core/buttons', 'core/button'])) {
            $patterns[] = 'call_to_action';
        }

        if (in_array('core/columns', $block_names, true)) {
            $patterns[] = 'column_layout';
        }

        if (in_array('core/cover', $block_names, true)) {
            $patterns[] = 'hero_or_cover_section';
        }

        if (array_intersect($block_names, ['core/image', 'core/gallery', 'core/media-text', 'core/video'])) {
            $patterns[] = 'media_rich_section';
        }

        if (in_array('core/query', $block_names, true)) {
            $patterns[] = 'content_listing';
        }

        if (in_array('core/navigation', $block_names, true)) {
            $patterns[] = 'navigation_area';
        }

        return $patterns;
    }
}
