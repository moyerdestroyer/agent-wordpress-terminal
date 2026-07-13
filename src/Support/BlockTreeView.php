<?php

/**
 * Agent-facing block tree views (normalized tree, flat list, fingerprints).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts parse_blocks() arrays into compact, path-addressable summaries.
 */
final class BlockTreeView {
    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function normalized(array $blocks): array {
        return $this->normalize_blocks($blocks, '');
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    public function count(array $blocks): int {
        $count = 0;

        foreach ($blocks as $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            ++$count;
            $count += $this->count($this->inner_blocks($block));
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function flat_list(array $blocks, ?string $name_filter = null, int $max = 100): array {
        $max = max(1, min(500, $max));
        $items = [];
        $this->walk_flat($blocks, '', $name_filter, $max, $items);

        return $items;
    }

    /**
     * @param array<string, mixed> $block
     */
    public static function fingerprint(array $block): string {
        $data = [
            'name' => is_string($block['blockName'] ?? null) ? $block['blockName'] : '',
            'attrs' => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
            'innerHTML' => is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '',
        ];

        return hash('sha256', (string) wp_json_encode($data));
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function normalize_blocks(array $blocks, string $parent_path): array {
        $normalized = [];
        $visible_index = 0;

        foreach ($blocks as $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            $path = '' === $parent_path ? (string) $visible_index : $parent_path . '.' . $visible_index;
            $normalized[] = $this->format_block($block, $path);
            ++$visible_index;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function format_block(array $block, string $path): array {
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';

        return [
            'path' => $path,
            'name' => $block['blockName'],
            'attributes' => $attrs,
            'attributes_summary' => $this->summarize_attrs($attrs, true),
            'text_excerpt' => mb_substr(trim(wp_strip_all_tags($inner_html)), 0, 240, 'UTF-8'),
            'fingerprint' => self::fingerprint($block),
            'inner' => $this->normalize_blocks($this->inner_blocks($block), $path),
        ];
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<array<string, mixed>>              $items
     */
    private function walk_flat(
        array $blocks,
        string $parent_path,
        ?string $name_filter,
        int $max,
        array &$items,
    ): void {
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
                    'attributes_summary' => $this->summarize_attrs($attrs, false),
                    'text_excerpt' => mb_substr(trim(wp_strip_all_tags($inner_html)), 0, 120, 'UTF-8'),
                    'fingerprint' => self::fingerprint($block),
                ];
            }

            $this->walk_flat($this->inner_blocks($block), $path, $name_filter, $max, $items);
        }
    }

    /**
     * @param array<array-key, mixed> $attrs
     * @return array<string, mixed>
     */
    private function summarize_attrs(array $attrs, bool $include_array_counts): array {
        $summary = [];

        foreach ($attrs as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_scalar($value) || null === $value) {
                $summary[$key] = $value;
                continue;
            }

            if ($include_array_counts && is_array($value)) {
                $summary[$key] = sprintf('[%d item%s]', count($value), 1 === count($value) ? '' : 's');
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<int|string, array<string, mixed>>
     */
    private function inner_blocks(array $block): array {
        $inner_blocks = $block['innerBlocks'] ?? [];

        if (!is_array($inner_blocks)) {
            return [];
        }

        $normalized = [];

        foreach ($inner_blocks as $key => $inner_block) {
            if (!is_array($inner_block)) {
                continue;
            }

            $string_keyed = [];

            foreach ($inner_block as $item_key => $item) {
                if (is_string($item_key)) {
                    $string_keyed[$item_key] = $item;
                }
            }

            $normalized[$key] = $string_keyed;
        }

        return $normalized;
    }
}
