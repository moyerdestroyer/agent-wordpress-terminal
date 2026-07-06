<?php

/**
 * Agent-facing block tree formatting.
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
final class BlockTreeFormatter {
    private BlockTreeArrays $arrays;

    public function __construct(?BlockTreeArrays $arrays = null) {
        $this->arrays = $arrays ?? new BlockTreeArrays();
    }

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
            $count += $this->count($this->arrays->inner_blocks($block));
        }

        return $count;
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
            'attributes_summary' => $this->summarize_attrs($attrs),
            'text_excerpt' => mb_substr(trim(wp_strip_all_tags($inner_html)), 0, 240, 'UTF-8'),
            'fingerprint' => self::fingerprint($block),
            'inner' => $this->normalize_blocks($this->arrays->inner_blocks($block), $path),
        ];
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
                continue;
            }

            if (is_array($value)) {
                $summary[$key] = sprintf('[%d item%s]', count($value), 1 === count($value) ? '' : 's');
            }
        }

        return $summary;
    }
}
