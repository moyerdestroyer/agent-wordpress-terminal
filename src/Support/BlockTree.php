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
final class BlockTree
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function __construct(
        private readonly array $blocks,
    ) {}

    /**
     * Parse post content into a block tree.
     */
    public static function from_content(string $content): self
    {
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
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * Whether a block has a non-empty block name.
     *
     * @param array<string, mixed> $block
     */
    public static function has_block_name(array $block): bool
    {
        $name = $block['blockName'] ?? '';

        return is_string($name) && '' !== $name;
    }
}
