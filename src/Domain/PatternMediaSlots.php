<?php

/**
 * Describes semantic image destinations in an expanded pattern.
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

/** Lets an agent choose a theme-authored media slot instead of guessing an insertion point. */
final class PatternMediaSlots {
    /**
     * @return list<array{block_path: string, block_name: string, slot: string, occupied: bool, recommended_placement: string}>
     */
    public function from_content(string $content): array {
        $slots = [];
        $this->walk(BlockTree::from_content($content)->blocks(), '', $slots);

        return $slots;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<array{block_path: string, block_name: string, slot: string, occupied: bool, recommended_placement: string}> $slots
     */
    private function walk(array $blocks, string $parent_path, array &$slots): void {
        $visible_index = 0;

        foreach ($blocks as $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            $path = '' === $parent_path ? (string) $visible_index : $parent_path . '.' . $visible_index;
            $name = (string) ($block['blockName'] ?? '');
            $attrs = ArrayKey::as_map($block['attrs'] ?? null);
            ++$visible_index;

            if ('core/cover' === $name) {
                $slots[] = [
                    'block_path' => $path,
                    'block_name' => $name,
                    'slot' => 'cover_background',
                    'occupied' =>
                        (bool) ($attrs['useFeaturedImage'] ?? false)
                            || (int) ($attrs['id'] ?? 0) > 0
                            || '' !== (string) ($attrs['url'] ?? ''),
                    'recommended_placement' => 'featured_cover',
                ];
            }

            $this->walk(ArrayKey::list_of_maps($block['innerBlocks'] ?? null), $path, $slots);
        }
    }
}
