<?php

/**
 * Shared dotted-path helpers for block trees.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Parses and indexes visible block paths.
 */
final class BlockTreePathSegments {
    /**
     * @return list<int>
     */
    public function segments(string $path): array {
        $path = trim($path);

        if ('' === $path || !preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return [];
        }

        return array_map('intval', explode('.', $path));
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    public function raw_index_for_visible(array $blocks, int $visible_target): ?int {
        $visible_index = 0;

        foreach ($blocks as $raw_index => $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index === $visible_target) {
                return (int) $raw_index;
            }

            ++$visible_index;
        }

        return null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    public function visible_count(array $blocks): int {
        $count = 0;

        foreach ($blocks as $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            ++$count;
        }

        return $count;
    }
}
