<?php

/**
 * Block remove operations.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Removes blocks from parse_blocks() trees by dotted path.
 */
final class BlockTreeRemove {
    private BlockTreeArrays $arrays;
    private BlockTreePathSegments $paths;

    public function __construct(?BlockTreeArrays $arrays = null, ?BlockTreePathSegments $paths = null) {
        $this->arrays = $arrays ?? new BlockTreeArrays();
        $this->paths = $paths ?? new BlockTreePathSegments();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{content: string, removed: array<string, mixed>}|\WP_Error
     */
    public function remove(array $blocks, string $path, string $expected_fingerprint = ''): array|\WP_Error {
        $segments = $this->paths->segments($path);

        if ([] === $segments) {
            return $this->error('awpt_invalid_block_path', __(
                'Block path must be a dotted numeric path such as 0 or 2.1.',
                'agent-wordpress-terminal',
            ));
        }

        $working = $blocks;
        $removed = $this->remove_at($working, $segments, $expected_fingerprint);

        if (is_wp_error($removed)) {
            return $removed;
        }

        return [
            'content' => $this->serialize($working),
            'removed' => $removed,
        ];
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     * @return array<string, mixed>|\WP_Error
     */
    private function remove_at(array &$blocks, array $segments, string $expected_fingerprint): array|\WP_Error {
        $target = array_shift($segments);

        if (null === $target) {
            return $this->error('awpt_invalid_block_path', __('Block path is empty.', 'agent-wordpress-terminal'));
        }

        if ([] !== $segments) {
            return $this->remove_nested($blocks, $target, $segments, $expected_fingerprint);
        }

        $raw_index = $this->paths->raw_index_for_visible($blocks, $target);

        if (null === $raw_index) {
            return $this->error(
                'awpt_block_not_found',
                __('Block path was not found.', 'agent-wordpress-terminal'),
                404,
            );
        }

        $block = $blocks[$raw_index];

        if (
            '' !== $expected_fingerprint
            && !hash_equals($expected_fingerprint, BlockTreeFormatter::fingerprint($block))
        ) {
            return $this->error(
                'awpt_block_fingerprint_mismatch',
                __('The target block changed since the proposal was staged.', 'agent-wordpress-terminal'),
                409,
            );
        }

        array_splice($blocks, $raw_index, 1);

        return $block;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     * @return array<string, mixed>|\WP_Error
     */
    private function remove_nested(
        array &$blocks,
        int $target,
        array $segments,
        string $expected_fingerprint,
    ): array|\WP_Error {
        $visible_index = 0;

        foreach ($blocks as &$block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index !== $target) {
                ++$visible_index;
                continue;
            }

            $inner = $this->arrays->inner_blocks($block);
            $removed = $this->remove_at($inner, $segments, $expected_fingerprint);
            $block['innerBlocks'] = $inner;

            return $removed;
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
