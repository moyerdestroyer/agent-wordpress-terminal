<?php

/**
 * Shared path helpers for block tree edits.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Dotted-path navigation and serialize helpers.
 */
final class BlockTreePathHelpers {
    /**
     * @return list<int>
     */
    public function path_segments(string $path): array {
        $path = trim($path);

        if ('' === $path || !preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return [];
        }

        return array_map('intval', explode('.', $path));
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    public function raw_index_for_visible(array $blocks, int $visible_target): ?int {
        $visible_index = 0;

        foreach ($blocks as $raw_index => $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index === $visible_target) {
                return (int) $raw_index;
            }

            ++$visible_index;
        }

        return null;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */

    public function visible_count(array $blocks): int {
        $count = 0;

        foreach ($blocks as $block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<int|string, array<string, mixed>>
     */

    public function inner_blocks(array $block): array {
        $inner_blocks = $block['innerBlocks'] ?? [];

        if (!is_array($inner_blocks)) {
            return [];
        }

        $normalized = [];

        foreach ($inner_blocks as $key => $inner_block) {
            if (!is_array($inner_block)) {
                continue;
            }

            $string_keyed = [];

            foreach ($inner_block as $item_key => $item) {
                if (is_string($item_key)) {
                    $string_keyed[$item_key] = $item;
                }
            }

            $normalized[$key] = $string_keyed;
        }

        return $normalized;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */

    public function serialize(array $blocks): string {
        /** @var array<int|string, array{attrs: array<array-key, mixed>, blockName: null|string, innerBlocks: array<array-key, array<array-key, mixed>>, innerContent: array<array-key, mixed>, innerHTML: string}> $serializable */
        $serializable = $blocks;

        return serialize_blocks($serializable);
    }

    public function error(string $code, string $message, int $status = 400): \WP_Error {
        return new \WP_Error(code: $code, message: $message, data: ['status' => $status]);
    }
}
