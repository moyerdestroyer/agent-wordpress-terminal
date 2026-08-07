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
     * Compact a normalized block tree for compose evidence while retaining
     * every path + fingerprint needed for batch updates.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array{blocks: list<array<string, mixed>>, count: int, truncated_excerpts?: bool}
     */
    public function compact_for_evidence(array $blocks, int $max_encoded_bytes = 12_000): array {
        $max_encoded_bytes = max(2_000, $max_encoded_bytes);
        $excerpt_limit = 120;
        $truncated_excerpts = false;

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $compacted = $this->compact_nodes($blocks, $excerpt_limit, true);
            $encoded = wp_json_encode($compacted);
            $size = is_string($encoded) ? strlen($encoded) : $max_encoded_bytes + 1;

            if ($size <= $max_encoded_bytes) {
                return [
                    'blocks' => $compacted,
                    'count' => $this->count_normalized($compacted),
                    'truncated_excerpts' => $truncated_excerpts,
                ];
            }

            $excerpt_limit = max(0, (int) floor($excerpt_limit / 2));
            $truncated_excerpts = true;
        }

        // Last resort: fingerprint-complete flat index (paths encode hierarchy).
        // Drop excerpts/attrs so path+name+fingerprint fit denser pages.
        $flat = $this->flat_from_normalized($blocks, 500, true);

        return [
            'blocks' => $flat,
            'count' => count($flat),
            'truncated_excerpts' => true,
            'flat_index' => true,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks Already-normalized nodes.
     * @return list<array<string, mixed>>
     */
    private function compact_nodes(array $blocks, int $excerpt_limit, bool $prefer_summary_attrs): array {
        $out = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $node = [
                'path' => (string) ($block['path'] ?? ''),
                'name' => (string) ($block['name'] ?? ''),
                'fingerprint' => (string) ($block['fingerprint'] ?? ''),
            ];

            $summary = is_array($block['attributes_summary'] ?? null)
                ? $block['attributes_summary']
                : [];
            $attrs = is_array($block['attributes'] ?? null) ? $block['attributes'] : [];

            if ($prefer_summary_attrs && [] !== $summary) {
                $node['attributes_summary'] = $summary;
            } elseif ([] !== $attrs) {
                // Keep full attrs only when summary is empty (non-scalar style edits).
                $node['attributes'] = $attrs;
            } elseif ([] !== $summary) {
                $node['attributes_summary'] = $summary;
            }

            $excerpt = trim((string) ($block['text_excerpt'] ?? ''));

            if ('' !== $excerpt && $excerpt_limit > 0) {
                $node['text_excerpt'] = mb_substr($excerpt, 0, $excerpt_limit, 'UTF-8');
            }

            $inner = is_array($block['inner'] ?? null) ? $block['inner'] : [];

            if ([] !== $inner) {
                $node['inner'] = $this->compact_nodes($inner, $excerpt_limit, $prefer_summary_attrs);
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function count_normalized(array $blocks): int {
        $count = 0;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            ++$count;
            $inner = is_array($block['inner'] ?? null) ? $block['inner'] : [];
            $count += $this->count_normalized($inner);
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function flat_from_normalized(array $blocks, int $max, bool $minimal = false): array {
        $items = [];
        $this->collect_flat_normalized($blocks, $max, $items, $minimal);

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param list<array<string, mixed>>      $items
     */
    private function collect_flat_normalized(array $blocks, int $max, array &$items, bool $minimal = false): void {
        foreach ($blocks as $block) {
            if (count($items) >= $max || !is_array($block)) {
                return;
            }

            $entry = [
                'path' => (string) ($block['path'] ?? ''),
                'name' => (string) ($block['name'] ?? ''),
                'fingerprint' => (string) ($block['fingerprint'] ?? ''),
            ];

            if (!$minimal) {
                $summary = is_array($block['attributes_summary'] ?? null) ? $block['attributes_summary'] : [];

                if ([] !== $summary) {
                    $entry['attributes_summary'] = $summary;
                }

                $excerpt = trim((string) ($block['text_excerpt'] ?? ''));

                if ('' !== $excerpt) {
                    $entry['text_excerpt'] = mb_substr($excerpt, 0, 40, 'UTF-8');
                }
            }

            $items[] = $entry;
            $inner = is_array($block['inner'] ?? null) ? $block['inner'] : [];
            $this->collect_flat_normalized($inner, $max, $items, $minimal);
        }
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
        $attrs = ArrayKey::string_map($attrs);

        foreach (array_keys($attrs) as $key) {
            $value = ArrayKey::passthrough($attrs[$key] ?? null);

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
     * @return list<array<string, mixed>>
     */
    private function inner_blocks(array $block): array {
        return ArrayKey::list_of_maps($block['innerBlocks'] ?? null);
    }
}
