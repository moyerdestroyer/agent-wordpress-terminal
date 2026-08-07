<?php

/**
 * Block tree structure mutations (insert / remove / replace / append).
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
        $position = strtolower(trim($position));
        $position = match ($position) {
            'prepend', 'start', 'above' => BlockTree::POSITION_BEFORE,
            'end', 'bottom', 'below' => BlockTree::POSITION_APPEND,
            default => $position,
        };
        $allowed = [
            BlockTree::POSITION_BEFORE,
            BlockTree::POSITION_AFTER,
            BlockTree::POSITION_APPEND,
        ];

        if ('replace' === $position) {
            return new \WP_Error(
                'awpt_pattern_replace_requires_content',
                __(
                    'position "replace" is not a block insert. Use awpt/prepare-pattern-change with mode=replace, then awpt/propose-pattern-replace.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'allowed_positions' => $allowed,
                    'received_position' => 'replace',
                    'recommended_next_tools' => [[
                        'tool' => 'awpt/prepare-pattern-change',
                        'reason' => __(
                            'Prepare a section replacement with a verified target path and fingerprint, then stage with propose-pattern-replace.',
                            'agent-wordpress-terminal',
                        ),
                    ], [
                        'tool' => 'awpt/propose-pattern-replace',
                        'reason' => __(
                            'Stage a server-materialized section replacement without freehand markup.',
                            'agent-wordpress-terminal',
                        ),
                    ]],
                    'recovery' => __(
                        'Do not retry propose-pattern-insert with position replace. Call prepare-pattern-change (mode=replace) then propose-pattern-replace.',
                        'agent-wordpress-terminal',
                    ),
                ],
            );
        }

        if (!in_array($position, $allowed, true)) {
            return new \WP_Error(
                'awpt_invalid_block_position',
                __(
                    'Insert position must be before, after, or append.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'allowed_positions' => $allowed,
                    'received_position' => $position,
                    'recovery' => __(
                        'Use before, after, or append. For a full layout rewrite use awpt/propose-content-update with pattern_name and post_content instead of position replace.',
                        'agent-wordpress-terminal',
                    ),
                ],
            );
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
     * Insert an ordered composition. This deliberately reuses the single-block mutation
     * path so dotted paths, nesting, and serialization stay identical to normal edits.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $new_blocks
     * @return array{content: string, blocks: array<int, array<string, mixed>>, paths: list<string>}|\WP_Error
     */
    public function insert_blocks(array $blocks, string $path, array $new_blocks, string $position): array|\WP_Error {
        if ([] === $new_blocks) {
            return $this->paths->error('awpt_empty_block_composition', __(
                'A pattern must contain at least one block.',
                'agent-wordpress-terminal',
            ));
        }

        $working = $blocks;
        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        /** @var list<string> $paths */
        $paths = [];

        if (BlockTree::POSITION_BEFORE === $position) {
            $new_blocks = array_reverse($new_blocks);
        }

        $anchor = $path;

        foreach ($new_blocks as $raw) {
            $result = $this->insert_block($working, $anchor, $raw, $position);

            if (is_wp_error($result)) {
                return $result;
            }

            /** @var array<int, array<string, mixed>> $working */
            $working = parse_blocks($result['content']);
            $normalized[] = $result['block'];
            $paths[] = $result['path'];

            if (BlockTree::POSITION_AFTER === $position) {
                $anchor = $result['path'];
            }
        }

        if (BlockTree::POSITION_BEFORE === $position) {
            $normalized = array_reverse($normalized);
            $paths = array_reverse($paths);
        }

        return [
            'content' => $this->paths->serialize($working),
            'blocks' => $normalized,
            'paths' => $paths,
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
     * Replace one named block with one or more blocks at the same path index.
     *
     * Multi-root patterns expand in place: a single section path can become N
     * sibling blocks without rewriting unrelated sections.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $new_blocks
     * @return array{
     *   content: string,
     *   blocks: array<int, array<string, mixed>>,
     *   paths: list<string>,
     *   removed: array<string, mixed>
     * }|\WP_Error
     */
    public function replace_blocks(
        array $blocks,
        string $path,
        array $new_blocks,
        string $expected_fingerprint = '',
    ): array|\WP_Error {
        if ([] === $new_blocks) {
            return $this->paths->error('awpt_empty_block_composition', __(
                'A pattern must contain at least one block.',
                'agent-wordpress-terminal',
            ));
        }

        $segments = $this->paths->path_segments($path);

        if ([] === $segments) {
            return $this->paths->error('awpt_invalid_block_path', __(
                'Block path must be a dotted numeric path such as 0 or 2.1.',
                'agent-wordpress-terminal',
            ));
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];

        foreach ($new_blocks as $raw) {
            if (!is_array($raw) || !BlockTree::has_block_name($raw)) {
                return $this->paths->error('awpt_invalid_block', __(
                    'Replacement blocks must include a blockName.',
                    'agent-wordpress-terminal',
                ));
            }

            $normalized[] = $this->normalize_block($raw);
        }

        $working = $blocks;
        $result_paths = [];
        $removed = $this->replace_at($working, $segments, $normalized, $expected_fingerprint, $result_paths, '');

        if (is_wp_error($removed)) {
            return $removed;
        }

        return [
            'content' => $this->paths->serialize($working),
            'blocks' => $normalized,
            'paths' => $result_paths,
            'removed' => $removed,
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */

    public function normalize_block(array $block): array {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
        $attrs = ArrayKey::as_map($block['attrs'] ?? null);
        $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        $inner_blocks = [];

        foreach (ArrayKey::list_of_maps($block['innerBlocks'] ?? null) as $inner_block) {
            if (!BlockTree::has_block_name($inner_block)) {
                continue;
            }

            $inner_blocks[] = $this->normalize_block($inner_block);
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
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array<string, mixed>                    $new_block
     * @return true|\WP_Error
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
                $before_count = count($inner);
                $error = $this->append_at($inner, $segments, $new_block, $parent_path, $result_path);
                $block['innerBlocks'] = $inner;

                if (!is_wp_error($error) && count($inner) > $before_count) {
                    $this->insert_inner_content_placeholder($block, $this->first_path_segment($result_path));
                }

                return $error;
            }

            $inner = $this->paths->inner_blocks($block);
            $inserted_index = $this->paths->visible_count($inner);
            $inner[] = $new_block;
            $block['innerBlocks'] = $inner;
            $this->insert_inner_content_placeholder($block, $inserted_index);
            $result_path = $parent_path . '.' . $inserted_index;

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
                $before_count = count($inner);
                $error = $this->insert_relative($inner, $segments, $new_block, $position, $result_path);
                $block['innerBlocks'] = $inner;

                if (!is_wp_error($error)) {
                    if (count($inner) > $before_count) {
                        $this->insert_inner_content_placeholder($block, $this->first_path_segment($result_path));
                    }

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

    private function first_path_segment(string $path): int {
        $segments = explode('.', $path, 2);

        return max(0, (int) ($segments[0] ?? 0));
    }

    /**
     * Keep serialize_block()'s null-to-innerBlock map aligned after a nested
     * insertion. WordPress stores wrapper HTML and child placeholders
     * separately; changing only innerBlocks can make a later edit discard an
     * earlier inserted child.
     *
     * @param array<string, mixed> $parent
     */
    private function insert_inner_content_placeholder(array &$parent, int $child_index): void {
        $inner_content = is_array($parent['innerContent'] ?? null) ? $parent['innerContent'] : [];

        if ([] === $inner_content) {
            $parent['innerContent'] = [null];

            return;
        }

        $null_positions = [];

        foreach ($inner_content as $index => $part) {
            if (null === $part) {
                $null_positions[] = (int) $index;
            }
        }

        if (array_key_exists($child_index, $null_positions)) {
            $insert_at = $null_positions[$child_index];
        } elseif ([] !== $null_positions) {
            $insert_at = (int) end($null_positions) + 1;
        } else {
            $insert_at = count($inner_content);
        }

        array_splice($inner_content, $insert_at, 0, [null]);
        $parent['innerContent'] = $inner_content;
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
     * @param list<int>                               $segments
     * @param list<array<string, mixed>>              $new_blocks
     * @param list<string>                            $result_paths
     * @return array<string, mixed>|\WP_Error
     */
    private function replace_at(
        array &$blocks,
        array $segments,
        array $new_blocks,
        string $expected_fingerprint,
        array &$result_paths,
        string $parent_prefix,
    ): array|\WP_Error {
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
                $prefix = '' === $parent_prefix ? (string) $target : $parent_prefix . '.' . $target;
                $before_count = count($inner);
                $leaf_index = $segments[0] ?? 0;
                $removed = $this->replace_at(
                    $inner,
                    $segments,
                    $new_blocks,
                    $expected_fingerprint,
                    $result_paths,
                    $prefix,
                );
                $block['innerBlocks'] = $inner;

                if (!is_wp_error($removed) && count($inner) !== $before_count) {
                    // Nested multi-root replace can add siblings; keep innerContent aligned
                    // using the leaf visible index (not this parent path segment).
                    $delta = count($inner) - $before_count;

                    for ($i = 0; $i < $delta; ++$i) {
                        $this->insert_inner_content_placeholder($block, $leaf_index + 1 + $i);
                    }
                }

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

        array_splice($blocks, $raw_index, 1, $new_blocks);

        for ($i = 0; $i < count($new_blocks); ++$i) {
            $visible = $target + $i;
            $result_paths[] = '' === $parent_prefix ? (string) $visible : $parent_prefix . '.' . $visible;
        }

        return $block;
    }
}
