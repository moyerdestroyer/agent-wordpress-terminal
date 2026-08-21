<?php

/**
 * Maps an existing block document into a full-page layout pattern.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;
use AWPT\Support\BlockTree;
use AWPT\Support\BlockTreePathHelpers;

if (!defined('ABSPATH')) {
    exit();
}

/** Preserves source blocks while replacing only the document layout chrome. */
final class PatternDocumentContentMapper {
    public function map(string $pattern_content, string $source_content): string|\WP_Error {
        $source = array_values(array_filter(
            ArrayKey::list_of_maps(parse_blocks($source_content)),
            BlockTree::has_block_name(...),
        ));

        if ([] === $source) {
            return new \WP_Error(
                'awpt_document_source_empty',
                __('The existing document has no source blocks to map into the layout.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        $minimum_level = $this->minimum_heading_level($source);
        $minimum_level = 7 === $minimum_level ? 2 : $minimum_level;
        $seen_anchors = [];
        $outline = [];
        $source = $this->normalize_source_headings($source, $minimum_level, $seen_anchors, $outline);
        $layout = ArrayKey::list_of_maps(parse_blocks($pattern_content));
        $content_path = array_values(array_map('intval', $this->best_content_container_path($layout)));

        if ([] === $content_path || !$this->replace_children($layout, $content_path, $source)) {
            return new \WP_Error(
                'awpt_pattern_document_slot_missing',
                __(
                    'The selected full-page pattern has no identifiable article/content container for the existing blocks.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 409,
                    'recovery' => __(
                        'Choose another full-page pattern with an article or Main content group, or use a complete full-document content proposal.',
                        'agent-wordpress-terminal',
                    ),
                ],
            );
        }

        if ([] !== $outline) {
            $navigation_path = array_values(array_map('intval', $this->first_block_path($layout, 'core/navigation')));

            if ([] !== $navigation_path) {
                $this->replace_children($layout, $navigation_path, $this->navigation_links($outline));
            }
        }

        return new BlockTreePathHelpers()->serialize($layout);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function minimum_heading_level(array $blocks): int {
        $minimum = 7;

        foreach ($blocks as $block) {
            if ('core/heading' === (string) ($block['blockName'] ?? '')) {
                $attrs = ArrayKey::as_map($block['attrs'] ?? null);
                $minimum = min($minimum, max(1, min(6, (int) ($attrs['level'] ?? 2))));
            }

            $inner = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);

            if ([] !== $inner) {
                $minimum = min($minimum, $this->minimum_heading_level($inner));
            }
        }

        // Keep the sentinel while recursing. Converting an empty child subtree
        // to level 2 here incorrectly pulls a document whose real headings are
        // all H4 down to a minimum of H2, preventing the intended H4 -> H2
        // normalization.
        return $minimum;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, true> $seen_anchors
     * @param list<array{label: string, anchor: string}> $outline
     * @return list<array<string, mixed>>
     */
    private function normalize_source_headings(
        array $blocks,
        int $minimum_level,
        array &$seen_anchors,
        array &$outline,
    ): array {
        $offset = 2 - $minimum_level;

        foreach ($blocks as &$block) {
            if ('core/heading' === (string) ($block['blockName'] ?? '')) {
                $attrs = ArrayKey::as_map($block['attrs'] ?? null);
                $level = max(2, min(6, (int) ($attrs['level'] ?? 2) + $offset));
                $label = trim(wp_strip_all_tags((string) ($block['innerHTML'] ?? '')));
                $anchor = $this->unique_anchor(sanitize_title($label), $seen_anchors);
                $attrs['level'] = $level;

                if ('' !== $anchor) {
                    $attrs['anchor'] = $anchor;
                    $outline[] = ['label' => $label, 'anchor' => $anchor];
                }

                $block['attrs'] = $attrs;
                $block['innerHTML'] = $this->heading_markup((string) ($block['innerHTML'] ?? ''), $level, $anchor);
                $inner_content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];
                $block['innerContent'] = array_map(fn(mixed $part): mixed => is_string($part)
                    ? $this->heading_markup($part, $level, $anchor)
                    : $part, $inner_content);
            }

            $inner = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);

            if ([] !== $inner) {
                $block['innerBlocks'] = $this->normalize_source_headings(
                    $inner,
                    $minimum_level,
                    $seen_anchors,
                    $outline,
                );
            }
        }
        unset($block);

        return $blocks;
    }

    /** @param array<string, true> $seen */
    private function unique_anchor(string $candidate, array &$seen): string {
        if ('' === $candidate) {
            return '';
        }

        $anchor = $candidate;
        $suffix = 2;

        while (isset($seen[$anchor])) {
            $anchor = $candidate . '-' . $suffix;
            ++$suffix;
        }

        $seen[$anchor] = true;

        return $anchor;
    }

    private function heading_markup(string $markup, int $level, string $anchor): string {
        $markup = (string) preg_replace_callback(
            '/<h[1-6](\b[^>]*)>/i',
            static function (array $matches) use ($level, $anchor): string {
                $attributes = $matches[1] ?? '';

                if ('' !== $anchor && 1 !== preg_match('/\sid=["\']/i', $attributes)) {
                    $attributes .= ' id="' . esc_attr($anchor) . '"';
                }

                return '<h' . $level . $attributes . '>';
            },
            $markup,
            1,
        );

        return (string) preg_replace('/<\/h[1-6]>/i', '</h' . $level . '>', $markup, 1);
    }

    /** @param list<array<string, mixed>> $blocks @return list<int> */
    private function best_content_container_path(array $blocks): array {
        $best_path = [];
        $best_score = 0;
        $this->find_content_container($blocks, [], $best_path, $best_score);

        return $best_path;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<int> $prefix
     * @param list<int> $best_path
     */
    private function find_content_container(array $blocks, array $prefix, array &$best_path, int &$best_score): void {
        foreach ($blocks as $index => $block) {
            $path = [...$prefix, (int) $index];
            $attrs = ArrayKey::as_map($block['attrs'] ?? null);
            $metadata = ArrayKey::as_map($attrs['metadata'] ?? null);
            $name = strtolower(trim((string) ($metadata['name'] ?? '')));
            $class = strtolower(trim((string) ($attrs['className'] ?? '')));
            $score = match (true) {
                'article' === strtolower((string) ($attrs['tagName'] ?? '')) => 100,
                str_contains($class, 'content-main') => 90,
                in_array($name, ['main', 'article', 'main content'], true) => 80,
                'content' === $name => 50,
                default => 0,
            };

            if ($score > $best_score && 'core/group' === (string) ($block['blockName'] ?? '')) {
                $best_score = $score;
                $best_path = $path;
            }

            $this->find_content_container(
                ArrayKey::list_of_maps($block['innerBlocks'] ?? null),
                $path,
                $best_path,
                $best_score,
            );
        }
    }

    /** @param list<array<string, mixed>> $blocks @return list<int> */
    private function first_block_path(array $blocks, string $block_name, array $prefix = []): array {
        foreach ($blocks as $index => $block) {
            $path = [...$prefix, (int) $index];

            if ($block_name === (string) ($block['blockName'] ?? '')) {
                return $path;
            }

            $nested = $this->first_block_path(
                ArrayKey::list_of_maps($block['innerBlocks'] ?? null),
                $block_name,
                $path,
            );

            if ([] !== $nested) {
                return $nested;
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<int> $path
     * @param list<array<string, mixed>> $children
     */
    private function replace_children(array &$blocks, array $path, array $children): bool {
        $index = array_shift($path);

        if (null === $index || !isset($blocks[$index])) {
            return false;
        }

        if ([] !== $path) {
            $inner = ArrayKey::list_of_maps($blocks[$index]['innerBlocks'] ?? null);
            $replaced = $this->replace_children($inner, $path, $children);

            if ($replaced) {
                $blocks[$index]['innerBlocks'] = $inner;
            }

            return $replaced;
        }

        $blocks[$index]['innerBlocks'] = $children;
        $blocks[$index]['innerContent'] = $this->inner_content_for_children($blocks[$index], count($children));

        return true;
    }

    /** @param array<string, mixed> $block @return list<string|null> */
    private function inner_content_for_children(array $block, int $count): array {
        /** @var list<string|null> $parts */
        $parts = is_array($block['innerContent'] ?? null) ? array_values($block['innerContent']) : [];
        $first_null = array_search(null, $parts, true);
        $last_null = null;

        foreach ($parts as $index => $part) {
            if (null !== $part) {
                continue;
            }

            $last_null = (int) $index;
        }

        if (false !== $first_null && null !== $last_null) {
            $prefix = implode('', array_filter(array_slice($parts, 0, (int) $first_null), 'is_string'));
            $suffix = implode('', array_filter(array_slice($parts, $last_null + 1), 'is_string'));
        } else {
            $html = (string) ($block['innerHTML'] ?? '');
            $prefix = '';
            $suffix = '';
            $matches = [];

            if (1 === preg_match('/^(\s*<([a-z][a-z0-9-]*)\b[^>]*>).*?(<\/\2>\s*)$/is', $html, $matches)) {
                $prefix = $matches[1] ?? '';
                $suffix = $matches[3] ?? '';
            }
        }

        $inner_content = [$prefix];

        for ($index = 0; $index < $count; ++$index) {
            $inner_content[] = null;
            $inner_content[] = "\n";
        }

        $inner_content[] = $suffix;

        return $inner_content;
    }

    /**
     * @param list<array{label: string, anchor: string}> $outline
     * @return list<array<string, mixed>>
     */
    private function navigation_links(array $outline): array {
        $blocks = [];

        foreach ($outline as $item) {
            $json = wp_json_encode([
                'label' => $item['label'],
                'url' => '#' . $item['anchor'],
                'kind' => 'custom',
                'isTopLevelLink' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($json)) {
                continue;
            }

            $parsed = parse_blocks('<!-- wp:navigation-link ' . $json . ' /-->');
            $block = ArrayKey::as_map($parsed[0] ?? null);

            if (BlockTree::has_block_name($block)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}
