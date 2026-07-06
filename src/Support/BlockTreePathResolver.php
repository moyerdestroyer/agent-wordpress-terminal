<?php

/**
 * Block path resolver.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves dotted block paths against parse_blocks() arrays.
 */
final class BlockTreePathResolver
{
    private BlockTreeArrays $arrays;

    public function __construct(?BlockTreeArrays $arrays = null)
    {
        $this->arrays = $arrays ?? new BlockTreeArrays();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    public function get_block(array $blocks, string $path): ?array
    {
        $segments = $this->path_segments($path);

        if ([] === $segments) {
            return null;
        }

        return $this->get_block_at_segments($blocks, $segments);
    }

    /**
     * @return list<int>
     */
    private function path_segments(string $path): array
    {
        $path = trim($path);

        if ('' === $path || !preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return [];
        }

        return array_map('intval', explode('.', $path));
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     * @return array<string, mixed>|null
     */
    private function get_block_at_segments(array $blocks, array $segments): ?array
    {
        $target = array_shift($segments);

        if (null === $target) {
            return null;
        }

        $visible_index = 0;

        foreach ($blocks as $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index !== $target) {
                ++$visible_index;
                continue;
            }

            return [] === $segments
                ? $block
                : $this->get_block_at_segments($this->arrays->inner_blocks($block), $segments);
        }

        return null;
    }
}
