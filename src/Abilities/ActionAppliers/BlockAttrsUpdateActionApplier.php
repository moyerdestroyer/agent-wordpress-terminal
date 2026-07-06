<?php

/**
 * Applies staged block attribute changes to current post content.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

use AWPT\Support\BlockTree;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Rebuilds post content for a staged Gutenberg block attribute operation.
 */
final class BlockAttrsUpdateActionApplier
{
    /**
     * @param array<string, mixed> $payload
     * @return string|\WP_Error
     */
    public function content_from_payload(int $post_id, array $payload): string|\WP_Error
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(
                code: 'awpt_post_not_found',
                message: __('Post not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $attrs = $this->attrs_from_payload($payload);

        if ([] === $attrs) {
            return new \WP_Error(
                code: 'awpt_empty_block_attrs',
                message: __('Block attribute update has no attributes to apply.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $updated = BlockTree::from_content($post->post_content)->update_attrs(
            (string) ($payload['block_path'] ?? ''),
            $attrs,
            (string) ($payload['expected_fingerprint'] ?? ''),
        );

        if (is_wp_error($updated)) {
            return $updated;
        }

        return $updated['content'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attrs_from_payload(array $payload): array
    {
        if (!is_array($payload['attrs'] ?? null)) {
            return [];
        }

        $attrs = [];

        foreach ($payload['attrs'] as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $attrs[$key] = $value;
        }

        return $attrs;
    }
}
