<?php

/**
 * Compact editable text slots for materialized patterns.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;
use AWPT\Support\BlockTree;

if (!defined('ABSPATH')) {
    exit();
}

/** Presents editable rich-text leaves without returning an entire block document. */
final class PatternEditableSlots {
    /** Dynamic collection descendants describe posts, not static page copy. */
    private const DYNAMIC_CONTAINERS = [
        'core/query',
        'core/post-template',
        'core/comments-query-loop',
    ];

    /**
     * @return list<array{block_path: string, block_name: string, current_text: string}>
     */
    public function from_content(string $content, int $max = 100): array {
        $slots = [];
        $this->walk(BlockTree::from_content($content)->blocks(), '', max(1, min(200, $max)), $slots);

        return $slots;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<array{block_path: string, block_name: string, current_text: string}> $slots
     */
    private function walk(array $blocks, string $parent_path, int $max, array &$slots): void {
        $visible_index = 0;

        foreach ($blocks as $block) {
            if (count($slots) >= $max) {
                return;
            }

            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            $path = '' === $parent_path ? (string) $visible_index : $parent_path . '.' . $visible_index;
            $name = (string) ($block['blockName'] ?? '');
            ++$visible_index;

            if (PatternTextUpdater::is_replaceable_slot($block)) {
                $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
                $text = trim(html_entity_decode(wp_strip_all_tags($inner_html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                // Empty paragraphs and headings are intentional authoring slots
                // in starter layouts. Returning them prevents a valid shell
                // pattern from becoming an unusable zero-slot composition.
                $slots[] = [
                    'block_path' => $path,
                    'block_name' => $name,
                    'current_text' => mb_substr($text, 0, 1_000, 'UTF-8'),
                ];
            }

            if (in_array($name, self::DYNAMIC_CONTAINERS, true)) {
                continue;
            }

            $inner_blocks = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);
            $this->walk($inner_blocks, $path, $max, $slots);
        }
    }
}
