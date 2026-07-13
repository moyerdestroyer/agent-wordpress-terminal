<?php

/**
 * Block tree structure mutations (insert / remove / append).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Path-based structure edits against parse_blocks() arrays.
 */
final class BlockTreeMutator {
    private BlockTreePathHelpers $paths;

    public function __construct(?BlockTreePathHelpers $paths = null) {
        $this->paths = $paths ?? new BlockTreePathHelpers();
    }

    public function insert_block(
        array $blocks,
        string $path,
        array $new_block,
        string $position = BlockTree::POSITION_AFTER,
    ): array|\WP_Error {
        $position = strtolower(trim($position));
        $allowed = [
            BlockTree::POSITION_BEFORE,
            BlockTree::POSITION_AFTER,
            BlockTree::POSITION_APPEND,
        ];

        if (!in_array($position, $allowed, true)) {
            return $this->paths->error('awpt_invalid_block_position', __(
                'Insert position must be before, after, or append.',
                'agent-wordpress-terminal',
            ));
        }

        if (!BlockTree::has_block_name($new_block)) {
            return $this->paths->error('awpt_invalid_block', __(
                'Inserted block must include a blockName.',
                'agent-wordpress-terminal',
            ));
        }

        $normalized = $this->normalize_block($new_block);
        $working = $blocks;
        $result_path = '';

        if (BlockTree::POSITION_APPEND === $position) {
            $error = $this->append($working, trim($path), $normalized, $result_path);
        } else {
            $segments = $this->paths->path_segments($path);

            if ([] === $segments) {
                return $this->paths->error('awpt_invalid_block_path', __(
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
            'content' => $this->paths->serialize($working),
            'block' => $normalized,
            'path' => $result_path,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{content: string, removed: array<string, mixed>}|\WP_Error
     */

    public function remove_block(array $blocks, string $path, string $expected_fingerprint = ''): array|\WP_Error {
        $segments = $this->paths->path_segments($path);

        if ([] === $segments) {
            return $this->paths->error('awpt_invalid_block_path', __(
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
            'content' => $this->paths->serialize($working),
            'removed' => $removed,
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */

    public function normalize_block(array $block): array {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        $inner_blocks = [];

        if (is_array($block['innerBlocks'] ?? null)) {
            foreach ($block['innerBlocks'] as $inner) {
                if (!is_array($inner)) {
                    continue;
                }

                /** @var array<string, mixed> $inner_block */
                $inner_block = [];

                foreach ($inner as $key => $value) {
                    if (is_string($key)) {
                        $inner_block[$key] = $value;
                    }
                }

                if (!BlockTree::has_block_name($inner_block)) {
                    continue;
                }

                $inner_blocks[] = $this->normalize_block($inner_block);
            }
        }

        $inner_content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : null;

        if (null === $inner_content) {
            $inner_content = [] === $inner_blocks ? [$inner_html] : array_fill(0, count($inner_blocks), null);
        }

        return [
            'blockName' => $name,
            'attrs' => $attrs,
            'innerBlocks' => $inner_blocks,
            'innerHTML' => $inner_html,
            'innerContent' => $inner_content,
        ];
    }

    /**
     * @return list<int>
     */

    private function append(array &$blocks, string $path, array $new_block, string &$result_path): true|\WP_Error {
        if ('' === $path) {
            $blocks[] = $new_block;
            $result_path = (string) max(0, $this->paths->visible_count($blocks) - 1);

            return true;
        }

        $segments = $this->paths->path_segments($path);

        if ([] === $segments) {
            return $this->paths->error('awpt_invalid_block_path', __(
                'Append parent path must be empty or a dotted numeric path.',
                'agent-wordpress-terminal',
            ));
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
                $inner = $this->paths->inner_blocks($block);
                $error = $this->append_at($inner, $segments, $new_block, $parent_path, $result_path);
                $block['innerBlocks'] = $inner;

                return $error;
            }

            $inner = $this->paths->inner_blocks($block);
            $inner[] = $new_block;
            $block['innerBlocks'] = $inner;
            $result_path = $parent_path . '.' . ($this->paths->visible_count($inner) - 1);

            return true;
        }

        return $this->paths->error(
            'awpt_block_not_found',
            __('Block path was not found.', 'agent-wordpress-terminal'),
            404,
        );
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
            return $this->paths->error('awpt_invalid_block_path', __(
                'Block path is empty.',
                'agent-wordpress-terminal',
            ));
        }

        if ([] !== $segments) {
            $visible_index = 0;

            foreach ($blocks as &$block) {
                if (!BlockTree::has_block_name($block)) {
                    continue;
                }

                if ($visible_index !== $target) {
                    ++$visible_index;
                    continue;
                }

                $inner = $this->paths->inner_blocks($block);
                $error = $this->insert_relative($inner, $segments, $new_block, $position, $result_path);
                $block['innerBlocks'] = $inner;

                if (!is_wp_error($error)) {
                    $result_path = (string) $target . '.' . $result_path;
                }

                return $error;
            }

            return $this->paths->error(
                'awpt_block_not_found',
                __('Block path was not found.', 'agent-wordpress-terminal'),
                404,
            );
        }

        $raw_index = $this->paths->raw_index_for_visible($blocks, $target);

        if (null === $raw_index) {
            return $this->paths->error(
                'awpt_block_not_found',
                __('Block path was not found.', 'agent-wordpress-terminal'),
                404,
            );
        }

        $insert_at = BlockTree::POSITION_BEFORE === $position ? $raw_index : $raw_index + 1;
        array_splice($blocks, $insert_at, 0, [$new_block]);
        $result_path = (string) (BlockTree::POSITION_BEFORE === $position ? $target : $target + 1);

        return true;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     * @return array<string, mixed>|\WP_Error
     */

    private function remove_at(array &$blocks, array $segments, string $expected_fingerprint): array|\WP_Error {
        $target = array_shift($segments);

        if (null === $target) {
            return $this->paths->error('awpt_invalid_block_path', __(
                'Block path is empty.',
                'agent-wordpress-terminal',
            ));
        }

        if ([] !== $segments) {
            $visible_index = 0;

            foreach ($blocks as &$block) {
                if (!BlockTree::has_block_name($block)) {
                    continue;
                }

                if ($visible_index !== $target) {
                    ++$visible_index;
                    continue;
                }

                $inner = $this->paths->inner_blocks($block);
                $removed = $this->remove_at($inner, $segments, $expected_fingerprint);
                $block['innerBlocks'] = $inner;

                return $removed;
            }

            return $this->paths->error(
                'awpt_block_not_found',
                __('Block path was not found.', 'agent-wordpress-terminal'),
                404,
            );
        }

        $raw_index = $this->paths->raw_index_for_visible($blocks, $target);

        if (null === $raw_index) {
            return $this->paths->error(
                'awpt_block_not_found',
                __('Block path was not found.', 'agent-wordpress-terminal'),
                404,
            );
        }

        $block = $blocks[$raw_index];

        if ('' !== $expected_fingerprint && !hash_equals($expected_fingerprint, BlockTreeView::fingerprint($block))) {
            return $this->paths->error(
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
     */
}
