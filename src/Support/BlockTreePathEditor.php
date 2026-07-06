<?php

/**
 * Block path helper facade.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves and edits dotted block paths against parse_blocks() arrays.
 */
final class BlockTreePathEditor
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    public function get_block(array $blocks, string $path): ?array
    {
        return new BlockTreePathResolver()->get_block($blocks, $path);
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
        return new BlockTreeAttrsUpdater()->update_attrs($blocks, $path, $attrs, $expected_fingerprint);
    }
}
