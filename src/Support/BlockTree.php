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
        return new BlockTreeFormatter()->normalized($this->blocks);
    }

    /**
     * Count named blocks recursively.
     */
    public function count(): int {
        return new BlockTreeFormatter()->count($this->blocks);
    }

    /**
     * Resolve a named block by dotted path, e.g. "0" or "2.1".
     *
     * @return array<string, mixed>|null
     */
    public function get_block(string $path): ?array {
        return new BlockTreePathEditor()->get_block($this->blocks, $path);
    }

    /**
     * Merge attributes onto one block and return serialized content.
     *
     * @param array<string, mixed> $attrs
     * @return array{content: string, block: array<string, mixed>}|\WP_Error
     */
    public function update_attrs(string $path, array $attrs, string $expected_fingerprint = ''): array|\WP_Error {
        return new BlockTreePathEditor()->update_attrs($this->blocks, $path, $attrs, $expected_fingerprint);
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
        return BlockTreeFormatter::fingerprint($block);
    }
}
