<?php

/**
 * Typed block tree helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Wraps parse_blocks() output with typed accessors.
 */
final class BlockTree {
    public const POSITION_BEFORE = 'before';
    public const POSITION_AFTER = 'after';
    public const POSITION_APPEND = 'append';

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function __construct(
        private readonly array $blocks,
    ) {}

    /**
     * Parse post content into a block tree.
     */
    public static function from_content(string $content): self {
        $blocks = parse_blocks($content);
        $normalized = [];

        foreach ($blocks as $block) {
            $normalized[] = $block;
        }

        return new self($normalized);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocks(): array {
        return $this->blocks;
    }

    /**
     * Return normalized blocks with stable path identifiers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function normalized(): array {
        return new BlockTreeView()->normalized($this->blocks);
    }

    /**
     * Count named blocks recursively.
     */
    public function count(): int {
        return new BlockTreeView()->count($this->blocks);
    }

    /**
     * Resolve a named block by dotted path, e.g. "0" or "2.1".
     *
     * @return array<string, mixed>|null
     */
    public function get_block(string $path): ?array {
        return new BlockTreeEditor()->get_block($this->blocks, $path);
    }

    /**
     * Merge attributes onto one block and return serialized content.
     *
     * @param array<string, mixed> $attrs
     * @return array{content: string, block: array<string, mixed>}|\WP_Error
     */
    public function update_attrs(string $path, array $attrs, string $expected_fingerprint = ''): array|\WP_Error {
        return new BlockTreeEditor()->update_attrs($this->blocks, $path, $attrs, $expected_fingerprint);
    }

    /**
     * Insert a block relative to a path and return serialized content.
     *
     * @param array<string, mixed> $new_block
     * @return array{content: string, block: array<string, mixed>, path: string}|\WP_Error
     */
    public function insert_block(
        string $path,
        array $new_block,
        string $position = self::POSITION_AFTER,
    ): array|\WP_Error {
        return new BlockTreeEditor()->insert_block($this->blocks, $path, $new_block, $position);
    }

    /**
     * @param array<int, array<string, mixed>> $new_blocks
     * @return array{content: string, blocks: array<int, array<string, mixed>>, paths: list<string>}|\WP_Error
     */
    public function insert_blocks(
        string $path,
        array $new_blocks,
        string $position = self::POSITION_AFTER,
    ): array|\WP_Error {
        return new BlockTreeEditor()->insert_blocks($this->blocks, $path, $new_blocks, $position);
    }

    /**
     * Remove a block by path and return serialized content.
     *
     * @return array{content: string, removed: array<string, mixed>}|\WP_Error
     */
    public function remove_block(string $path, string $expected_fingerprint = ''): array|\WP_Error {
        return new BlockTreeEditor()->remove_block($this->blocks, $path, $expected_fingerprint);
    }

    /**
     * Flatten named blocks for list views.
     *
     * @return array<int, array<string, mixed>>
     */
    public function flat_list(?string $name_filter = null, int $max = 100): array {
        return new BlockTreeView()->flat_list($this->blocks, $name_filter, $max);
    }

    /**
     * Whether a block has a non-empty block name.
     *
     * @param array<string, mixed> $block
     */
    public static function has_block_name(array $block): bool {
        $name = $block['blockName'] ?? '';

        return is_string($name) && '' !== $name;
    }

    /**
     * @param array<string, mixed> $block
     */
    public static function fingerprint(array $block): string {
        return BlockTreeView::fingerprint($block);
    }
}
