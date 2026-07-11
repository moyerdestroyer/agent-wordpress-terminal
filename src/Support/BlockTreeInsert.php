<?php

/**
 * Block insert operations.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Inserts blocks into parse_blocks() trees.
 */
final class BlockTreeInsert {
    private BlockTreeArrays $arrays;
    private BlockTreePathSegments $paths;

    public function __construct(?BlockTreeArrays $arrays = null, ?BlockTreePathSegments $paths = null) {
        $this->arrays = $arrays ?? new BlockTreeArrays();
        $this->paths = $paths ?? new BlockTreePathSegments();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed>             $new_block
     * @return array{content: string, block: array<string, mixed>, path: string}|\WP_Error
     */
    public function insert(array $blocks, string $path, array $new_block, string $position): array|\WP_Error {
        $position = strtolower(trim($position));
        $allowed = [
            BlockTreeStructureMutator::POSITION_BEFORE,
            BlockTreeStructureMutator::POSITION_AFTER,
            BlockTreeStructureMutator::POSITION_APPEND,
        ];

        if (!in_array($position, $allowed, true)) {
            return $this->error('awpt_invalid_block_position', __(
                'Insert position must be before, after, or append.',
                'agent-wordpress-terminal',
            ));
        }

        if (!BlockTree::has_block_name($new_block)) {
            return $this->error('awpt_invalid_block', __(
                'Inserted block must include a blockName.',
                'agent-wordpress-terminal',
            ));
        }

        $normalized = new BlockTreeStructureMutator($this->arrays)->normalize_block($new_block);
        $working = $blocks;
        $result_path = '';

        if (BlockTreeStructureMutator::POSITION_APPEND === $position) {
            $error = new BlockTreeAppend($this->arrays, $this->paths)->append(
                $working,
                trim($path),
                $normalized,
                $result_path,
            );
        } else {
            $segments = $this->paths->segments($path);

            if ([] === $segments) {
                return $this->error('awpt_invalid_block_path', __(
                    'Block path must be a dotted numeric path such as 0 or 2.1.',
                    'agent-wordpress-terminal',
                ));
            }

            $error = $this->insert_relative($working, $segments, $normalized, $position, $result_path);
        }

        if (is_wp_error($error)) {
            return $error;
        }

        return [
            'content' => $this->serialize($working),
            'block' => $normalized,
            'path' => $result_path,
        ];
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     * @param array<string, mixed>                    $new_block
     */
    private function insert_relative(
        array &$blocks,
        array $segments,
        array $new_block,
        string $position,
        string &$result_path,
    ): true|\WP_Error {
        $target = array_shift($segments);

        if (null === $target) {
            return $this->error('awpt_invalid_block_path', __('Block path is empty.', 'agent-wordpress-terminal'));
        }

        if ([] !== $segments) {
            return $this->insert_into_child(
                $blocks,
                [
                    'target' => $target,
                    'rest' => $segments,
                    'block' => $new_block,
                    'position' => $position,
                ],
                $result_path,
            );
        }

        $raw_index = $this->paths->raw_index_for_visible($blocks, $target);

        if (null === $raw_index) {
            return $this->error(
                'awpt_block_not_found',
                __('Block path was not found.', 'agent-wordpress-terminal'),
                404,
            );
        }

        $insert_at = BlockTreeStructureMutator::POSITION_BEFORE === $position ? $raw_index : $raw_index + 1;
        array_splice($blocks, $insert_at, 0, [$new_block]);
        $result_path = (string) (BlockTreeStructureMutator::POSITION_BEFORE === $position ? $target : $target + 1);

        return true;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array{target: int, rest: list<int>, block: array<string, mixed>, position: string} $job
     */
    private function insert_into_child(array &$blocks, array $job, string &$result_path): true|\WP_Error {
        $visible_index = 0;
        $target = $job['target'];

        foreach ($blocks as &$block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index !== $target) {
                ++$visible_index;
                continue;
            }

            $inner = $this->arrays->inner_blocks($block);
            $error = $this->insert_relative($inner, $job['rest'], $job['block'], $job['position'], $result_path);
            $block['innerBlocks'] = $inner;

            if (!is_wp_error($error)) {
                $result_path = (string) $target . '.' . $result_path;
            }

            return $error;
        }

        return $this->error('awpt_block_not_found', __('Block path was not found.', 'agent-wordpress-terminal'), 404);
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    private function serialize(array $blocks): string {
        /** @var array<int|string, array{attrs: array<array-key, mixed>, blockName: null|string, innerBlocks: array<array-key, array<array-key, mixed>>, innerContent: array<array-key, mixed>, innerHTML: string}> $serializable */
        $serializable = $blocks;

        return serialize_blocks($serializable);
    }

    private function error(string $code, string $message, int $status = 400): \WP_Error {
        return new \WP_Error(code: $code, message: $message, data: ['status' => $status]);
    }
}
