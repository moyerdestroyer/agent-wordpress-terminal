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
final class BlockTreePathEditor {
    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    public function get_block(array $blocks, string $path): ?array {
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

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed>             $new_block
     * @return array{content: string, block: array<string, mixed>, path: string}|\WP_Error
     */
    public function insert_block(
        array $blocks,
        string $path,
        array $new_block,
        string $position = BlockTreeStructureMutator::POSITION_AFTER,
    ): array|\WP_Error {
        return new BlockTreeStructureMutator()->insert_block($blocks, $path, $new_block, $position);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{content: string, removed: array<string, mixed>}|\WP_Error
     */
    public function remove_block(array $blocks, string $path, string $expected_fingerprint = ''): array|\WP_Error {
        return new BlockTreeStructureMutator()->remove_block($blocks, $path, $expected_fingerprint);
    }
}
