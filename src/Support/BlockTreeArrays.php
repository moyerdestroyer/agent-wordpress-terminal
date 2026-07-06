<?php

/**
 * Block array normalization helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts mixed parse_blocks() children into analyzer-friendly block arrays.
 */
final class BlockTreeArrays {
    /**
     * @param array<string, mixed> $block
     * @return array<int|string, array<string, mixed>>
     */
    public function inner_blocks(array $block): array {
        $inner_blocks = $block['innerBlocks'] ?? [];

        if (!is_array($inner_blocks)) {
            return [];
        }

        $normalized = [];

        foreach ($inner_blocks as $key => $inner_block) {
            if (!is_array($inner_block)) {
                continue;
            }

            $normalized[$key] = $this->string_keyed_array($inner_block);
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private function string_keyed_array(array $value): array {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
