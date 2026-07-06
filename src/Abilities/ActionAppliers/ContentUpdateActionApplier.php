<?php

/**
 * Applies staged post content updates.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

use AWPT\Support\ActionOperations;
use AWPT\Support\PostContentSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Applies a staged post/page content update action.
 */
final class ContentUpdateActionApplier {
    private BlockAttrsUpdateActionApplier $block_attrs;

    public function __construct(?BlockAttrsUpdateActionApplier $block_attrs = null) {
        $this->block_attrs = $block_attrs ?? new BlockAttrsUpdateActionApplier();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error {
        $post_id = (int) ($payload['post_id'] ?? 0);

        if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
            return new \WP_Error(
                code: 'awpt_cannot_edit_post',
                message: __('You do not have permission to edit this post.', 'agent-wordpress-terminal'),
                data: ['status' => 403],
            );
        }

        $update = ['ID' => $post_id];

        if (array_key_exists('post_title', $payload)) {
            $update['post_title'] = sanitize_text_field((string) $payload['post_title']);
        }

        $operation = (string) ($payload['operation'] ?? ActionOperations::CONTENT_UPDATE);

        if (ActionOperations::BLOCK_ATTRS_UPDATE === $operation) {
            $rebuilt = $this->block_attrs->content_from_payload($post_id, $payload);

            if (is_wp_error($rebuilt)) {
                return $rebuilt;
            }

            $update['post_content'] = $rebuilt;
        } elseif (array_key_exists('post_content', $payload)) {
            $update['post_content'] = PostContentSanitizer::for_staged_update((string) $payload['post_content']);
        }

        if (array_key_exists('post_status', $payload)) {
            $status = sanitize_key((string) $payload['post_status']);

            if (!in_array($status, array_keys(get_post_statuses()), true)) {
                return new \WP_Error(
                    code: 'awpt_invalid_post_status',
                    message: __('Unsupported post status.', 'agent-wordpress-terminal'),
                    data: ['status' => 400],
                );
            }

            $update['post_status'] = $status;
        }

        $meta_changes = is_array($payload['post_meta'] ?? null) ? $payload['post_meta'] : [];

        if (1 === count($update) && [] === $meta_changes) {
            return new \WP_Error(
                code: 'awpt_empty_action',
                message: __('Action has no post changes to apply.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        if (count($update) > 1) {
            $updated = wp_update_post($update, wp_error: true);

            if (is_wp_error($updated)) {
                return $updated;
            }
        }

        foreach ($meta_changes as $key => $value) {
            $meta_key = sanitize_key((string) $key);

            if ('' === $meta_key) {
                continue;
            }

            update_post_meta($post_id, $meta_key, $value);
        }

        return ['post_id' => $post_id];
    }
}
