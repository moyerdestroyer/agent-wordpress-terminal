<?php

/**
 * Surgical block tree edits (get / attrs / insert / remove).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Path-based edits against parse_blocks() arrays (visible named blocks only).
 */
final class BlockTreeEditor {
    private BlockTreePathHelpers $paths;

    public function __construct(?BlockTreePathHelpers $paths = null) {
        $this->paths = $paths ?? new BlockTreePathHelpers();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed>             $new_block
     * @return array{content: string, block: array<string, mixed>, path: string}|\WP_Error
     */
    public function insert_block(
        array $blocks,
        string $path,
        array $new_block,
        string $position = BlockTree::POSITION_AFTER,
    ): array|\WP_Error {
        return new BlockTreeMutator()->insert_block($blocks, $path, $new_block, $position);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{content: string, removed: array<string, mixed>}|\WP_Error
     */
    public function remove_block(array $blocks, string $path, string $expected_fingerprint = ''): array|\WP_Error {
        return new BlockTreeMutator()->remove_block($blocks, $path, $expected_fingerprint);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public function normalize_block(array $block): array {
        return new BlockTreeMutator()->normalize_block($block);
    }

    public function get_block(array $blocks, string $path): ?array {
        $segments = $this->paths->path_segments($path);

        if ([] === $segments) {
            return null;
        }

        return $this->get_block_at_segments($blocks, $segments);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed>             $attrs
     * @return array{content: string, block: array<string, mixed>}|\WP_Error
     */

    public function update_attrs(
        array $blocks,
        string $path,
        array $attrs,
        string $expected_fingerprint = '',
    ): array|\WP_Error {
        $segments = $this->paths->path_segments($path);

        if ([] === $segments) {
            return $this->paths->error('awpt_invalid_block_path', __(
                'Block path must be a dotted numeric path such as 0 or 2.1.',
                'agent-wordpress-terminal',
            ));
        }

        $updated = $this->update_at_segments($blocks, $segments, $attrs, $expected_fingerprint);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return [
            'content' => $this->paths->serialize($blocks),
            'block' => $updated,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed>             $new_block
     * @return array{content: string, block: array<string, mixed>, path: string}|\WP_Error
     */

    private function get_block_at_segments(array $blocks, array $segments): ?array {
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
                : $this->get_block_at_segments($this->paths->inner_blocks($block), $segments);
        }

        return null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     * @param array<string, mixed>                    $attrs
     * @return array<string, mixed>|\WP_Error
     */

    private function update_at_segments(
        array &$blocks,
        array $segments,
        array $attrs,
        string $expected_fingerprint,
    ): array|\WP_Error {
        $target = array_shift($segments);

        if (null === $target) {
            return $this->paths->error('awpt_invalid_block_path', __(
                'Block path is empty.',
                'agent-wordpress-terminal',
            ));
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
                $inner_blocks = $this->paths->inner_blocks($block);
                $updated = $this->update_at_segments($inner_blocks, $segments, $attrs, $expected_fingerprint);
                $block['innerBlocks'] = $inner_blocks;

                return $updated;
            }

            if (
                '' !== $expected_fingerprint
                && !hash_equals($expected_fingerprint, BlockTreeView::fingerprint($block))
            ) {
                return $this->paths->error(
                    'awpt_block_fingerprint_mismatch',
                    __('The target block changed since the proposal was staged.', 'agent-wordpress-terminal'),
                    409,
                );
            }

            $current_attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $block['attrs'] = array_merge($current_attrs, $attrs);

            return $block;
        }

        return $this->paths->error(
            'awpt_block_not_found',
            __('Block path was not found.', 'agent-wordpress-terminal'),
            404,
        );
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array<string, mixed>                    $new_block
     */
}
