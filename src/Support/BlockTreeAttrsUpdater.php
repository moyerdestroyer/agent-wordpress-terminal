<?php

/**
 * Block attribute updater.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Updates one block's attributes by dotted path.
 */
final class BlockTreeAttrsUpdater {
    private BlockTreeArrays $arrays;

    public function __construct(?BlockTreeArrays $arrays = null) {
        $this->arrays = $arrays ?? new BlockTreeArrays();
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
        $segments = $this->path_segments($path);

        if ([] === $segments) {
            return $this->error(
                'awpt_invalid_block_path',
                __('Block path must be a dotted numeric path such as 0 or 2.1.', 'agent-wordpress-terminal'),
                400,
            );
        }

        $updated = $this->update_at_segments($blocks, $segments, $attrs, $expected_fingerprint);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return [
            'content' => $this->serialize($blocks),
            'block' => $updated,
        ];
    }

    /**
     * @return list<int>
     */
    private function path_segments(string $path): array {
        $path = trim($path);

        if ('' === $path || !preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return [];
        }

        return array_map('intval', explode('.', $path));
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
            return $this->error('awpt_invalid_block_path', __('Block path is empty.', 'agent-wordpress-terminal'), 400);
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

            return $this->update_found_block($block, $segments, $attrs, $expected_fingerprint);
        }

        return $this->error('awpt_block_not_found', __('Block path was not found.', 'agent-wordpress-terminal'), 404);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<int>            $segments
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>|\WP_Error
     */
    private function update_found_block(
        array &$block,
        array $segments,
        array $attrs,
        string $expected_fingerprint,
    ): array|\WP_Error {
        if ([] !== $segments) {
            return $this->update_inner_block($block, $segments, $attrs, $expected_fingerprint);
        }

        if (
            '' !== $expected_fingerprint
            && !hash_equals($expected_fingerprint, BlockTreeFormatter::fingerprint($block))
        ) {
            return $this->error(
                'awpt_block_fingerprint_mismatch',
                __('The target block changed since the proposal was staged.', 'agent-wordpress-terminal'),
                409,
            );
        }

        $current_attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $block['attrs'] = array_merge($current_attrs, $attrs);

        return $block;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<int>            $segments
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>|\WP_Error
     */
    private function update_inner_block(
        array &$block,
        array $segments,
        array $attrs,
        string $expected_fingerprint,
    ): array|\WP_Error {
        $inner_blocks = $this->arrays->inner_blocks($block);
        $updated = $this->update_at_segments($inner_blocks, $segments, $attrs, $expected_fingerprint);
        $block['innerBlocks'] = $inner_blocks;

        return $updated;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    private function serialize(array $blocks): string {
        /** @var array<int|string, array{attrs: array<array-key, mixed>, blockName: null|string, innerBlocks: array<array-key, array<array-key, mixed>>, innerContent: array<array-key, mixed>, innerHTML: string}> $serializable_blocks */
        $serializable_blocks = $blocks;

        return serialize_blocks($serializable_blocks);
    }

    private function error(string $code, string $message, int $status): \WP_Error {
        return new \WP_Error(code: $code, message: $message, data: ['status' => $status]);
    }
}
