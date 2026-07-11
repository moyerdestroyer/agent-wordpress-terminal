<?php

/**
 * Append blocks into a tree.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Appends a block as the last child of a parent path (or root).
 */
final class BlockTreeAppend {
    private BlockTreeArrays $arrays;
    private BlockTreePathSegments $paths;

    public function __construct(?BlockTreeArrays $arrays = null, ?BlockTreePathSegments $paths = null) {
        $this->arrays = $arrays ?? new BlockTreeArrays();
        $this->paths = $paths ?? new BlockTreePathSegments();
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array<string, mixed>                    $new_block
     */
    public function append(array &$blocks, string $path, array $new_block, string &$result_path): true|\WP_Error {
        if ('' === $path) {
            $blocks[] = $new_block;
            $result_path = (string) max(0, $this->paths->visible_count($blocks) - 1);

            return true;
        }

        $segments = $this->paths->segments($path);

        if ([] === $segments) {
            return new \WP_Error(
                code: 'awpt_invalid_block_path',
                message: __('Append parent path must be empty or a dotted numeric path.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        return $this->append_at($blocks, $segments, $new_block, $path, $result_path);
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     * @param array<string, mixed>                    $new_block
     */
    private function append_at(
        array &$blocks,
        array $segments,
        array $new_block,
        string $parent_path,
        string &$result_path,
    ): true|\WP_Error {
        $target = array_shift($segments);

        if (null === $target) {
            return new \WP_Error(
                code: 'awpt_invalid_block_path',
                message: __('Block path is empty.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $visible_index = 0;

        foreach ($blocks as &$block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index !== $target) {
                ++$visible_index;
                continue;
            }

            if ([] !== $segments) {
                $inner = $this->arrays->inner_blocks($block);
                $error = $this->append_at($inner, $segments, $new_block, $parent_path, $result_path);
                $block['innerBlocks'] = $inner;

                return $error;
            }

            $inner = $this->arrays->inner_blocks($block);
            $inner[] = $new_block;
            $block['innerBlocks'] = $inner;
            $result_path = $parent_path . '.' . ($this->paths->visible_count($inner) - 1);

            return true;
        }

        return new \WP_Error(
            code: 'awpt_block_not_found',
            message: __('Block path was not found.', 'agent-wordpress-terminal'),
            data: ['status' => 404],
        );
    }
}
