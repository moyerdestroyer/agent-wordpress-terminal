<?php

/**
 * Applies staged block insert/remove operations.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

use AWPT\Support\ActionOperations;
use AWPT\Support\BlockTree;
use AWPT\Support\BlockTreeStructureMutator;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Rebuilds post content for staged block insert or remove.
 */
final class BlockStructureUpdateActionApplier {
    /**
     * @param array<string, mixed> $payload
     * @return string|\WP_Error
     */
    public function content_from_payload(int $post_id, array $payload): string|\WP_Error {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(
                code: 'awpt_post_not_found',
                message: __('Post not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $operation = (string) ($payload['operation'] ?? '');
        $path = (string) ($payload['block_path'] ?? '');
        $tree = BlockTree::from_content($post->post_content);

        if (ActionOperations::BLOCK_REMOVE === $operation) {
            $result = $tree->remove_block($path, (string) ($payload['expected_fingerprint'] ?? ''));

            return is_wp_error($result) ? $result : $result['content'];
        }

        if (ActionOperations::BLOCK_INSERT === $operation) {
            $raw = is_array($payload['block'] ?? null) ? $payload['block'] : [];
            $typed = [];

            foreach ($raw as $key => $value) {
                if (is_string($key)) {
                    $typed[$key] = $value;
                }
            }

            $block = new BlockTreeStructureMutator()->normalize_block($typed);
            $position = (string) ($payload['position'] ?? BlockTreeStructureMutator::POSITION_AFTER);
            $result = $tree->insert_block($path, $block, $position);

            return is_wp_error($result) ? $result : $result['content'];
        }

        return new \WP_Error(
            code: 'awpt_unsupported_block_operation',
            message: __('Unsupported block structure operation.', 'agent-wordpress-terminal'),
            data: ['status' => 400],
        );
    }
}
