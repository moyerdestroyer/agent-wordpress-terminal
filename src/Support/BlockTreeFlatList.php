<?php

/**
 * Flat block path listings.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Flattens parse_blocks() trees for agent list tools.
 */
final class BlockTreeFlatList {
    private BlockTreeArrays $arrays;

    public function __construct(?BlockTreeArrays $arrays = null) {
        $this->arrays = $arrays ?? new BlockTreeArrays();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function collect(array $blocks, ?string $name_filter = null, int $max = 100): array {
        $max = max(1, min(500, $max));
        $items = [];
        $this->walk($blocks, '', $name_filter, $max, $items);

        return $items;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<array<string, mixed>>              $items
     */
    private function walk(array $blocks, string $parent_path, ?string $name_filter, int $max, array &$items): void {
        $visible_index = 0;

        foreach ($blocks as $block) {
            if (count($items) >= $max) {
                return;
            }

            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            $path = '' === $parent_path ? (string) $visible_index : $parent_path . '.' . $visible_index;
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            ++$visible_index;

            if (null === $name_filter || '' === $name_filter || $name === $name_filter) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
                $items[] = [
                    'path' => $path,
                    'name' => $name,
                    'attributes_summary' => $this->summarize_attrs($attrs),
                    'text_excerpt' => mb_substr(trim(wp_strip_all_tags($inner_html)), 0, 120, 'UTF-8'),
                    'fingerprint' => BlockTreeFormatter::fingerprint($block),
                ];
            }

            $this->walk($this->arrays->inner_blocks($block), $path, $name_filter, $max, $items);
        }
    }

    /**
     * @param array<array-key, mixed> $attrs
     * @return array<string, mixed>
     */
    private function summarize_attrs(array $attrs): array {
        $summary = [];

        foreach ($attrs as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_scalar($value) || null === $value) {
                $summary[$key] = $value;
            }
        }

        return $summary;
    }
}
