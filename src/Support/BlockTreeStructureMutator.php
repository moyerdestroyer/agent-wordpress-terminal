<?php

/**
 * Insert/remove blocks by dotted path.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Surgical tree edits against parse_blocks() arrays (visible named blocks only).
 */
final class BlockTreeStructureMutator {
    public const POSITION_BEFORE = 'before';
    public const POSITION_AFTER = 'after';
    public const POSITION_APPEND = 'append';

    private BlockTreeArrays $arrays;

    public function __construct(?BlockTreeArrays $arrays = null) {
        $this->arrays = $arrays ?? new BlockTreeArrays();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed>             $new_block Parsed block shape.
     * @return array{content: string, block: array<string, mixed>, path: string}|\WP_Error
     */
    public function insert_block(
        array $blocks,
        string $path,
        array $new_block,
        string $position = self::POSITION_AFTER,
    ): array|\WP_Error {
        return new BlockTreeInsert($this->arrays)->insert($blocks, $path, $new_block, $position);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{content: string, removed: array<string, mixed>}|\WP_Error
     */
    public function remove_block(array $blocks, string $path, string $expected_fingerprint = ''): array|\WP_Error {
        return new BlockTreeRemove($this->arrays)->remove($blocks, $path, $expected_fingerprint);
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
}
