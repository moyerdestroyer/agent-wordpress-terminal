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
    private BlockStructureUpdateActionApplier $block_structure;

    public function __construct(
        ?BlockAttrsUpdateActionApplier $block_attrs = null,
        ?BlockStructureUpdateActionApplier $block_structure = null,
    ) {
        $this->block_attrs = $block_attrs ?? new BlockAttrsUpdateActionApplier();
        $this->block_structure = $block_structure ?? new BlockStructureUpdateActionApplier();
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

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $conflict = $this->concurrency_conflict($post, $payload);

        if (null !== $conflict) {
            return new \WP_Error(
                'awpt_action_conflict',
                sprintf(
                    /* translators: %s: changed field name. */
                    __(
                        'This proposal is stale because %s changed after it was staged. Review and restage it before applying.',
                        'agent-wordpress-terminal',
                    ),
                    $conflict,
                ),
                ['status' => 409, 'field' => $conflict],
            );
        }

        $update = ['ID' => $post_id];

        if (array_key_exists('post_title', $payload)) {
            $update['post_title'] = sanitize_text_field((string) $payload['post_title']);
        }

        $operation = (string) ($payload['operation'] ?? ActionOperations::CONTENT_UPDATE);

        if (
            in_array($operation, [ActionOperations::TEMPLATE_UPDATE, ActionOperations::GLOBAL_STYLES_UPDATE], true)
            && !current_user_can('edit_theme_options')
        ) {
            return new \WP_Error(
                code: 'awpt_cannot_edit_theme_data',
                message: __(
                    'You do not have permission to edit theme templates or global styles.',
                    'agent-wordpress-terminal',
                ),
                data: ['status' => 403],
            );
        }

        if (ActionOperations::BLOCK_ATTRS_UPDATE === $operation) {
            $rebuilt = $this->block_attrs->content_from_payload($post_id, $payload);

            if (is_wp_error($rebuilt)) {
                return $rebuilt;
            }

            $update['post_content'] = $rebuilt;
        } elseif (in_array(
            $operation,
            [
                ActionOperations::BLOCK_INSERT,
                ActionOperations::BLOCK_REMOVE,
                ActionOperations::PATTERN_INSERT,
            ],
            true,
        )) {
            $rebuilt = $this->block_structure->content_from_payload($post_id, $payload);

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

    /** @param array<string, mixed> $payload */
    private function concurrency_conflict(\WP_Post $post, array $payload): ?string {
        if (
            array_key_exists('post_title', $payload)
            && array_key_exists('original_post_title', $payload)
            && (string) $payload['original_post_title'] !== $post->post_title
        ) {
            return 'the title';
        }

        // Content-bearing ops store original_post_content so apply can detect
        // intervening edits (posts, templates, and global styles CPT content).
        if (
            array_key_exists('post_content', $payload)
            && array_key_exists('original_post_content', $payload)
            && (string) $payload['original_post_content'] !== $post->post_content
        ) {
            return 'the content';
        }

        if (
            array_key_exists('post_status', $payload)
            && array_key_exists('original_post_status', $payload)
            && (string) $payload['original_post_status'] !== $post->post_status
        ) {
            return 'the status';
        }

        $original_meta = is_array($payload['original_post_meta'] ?? null) ? $payload['original_post_meta'] : [];

        foreach (array_keys(is_array($payload['post_meta'] ?? null) ? $payload['post_meta'] : []) as $key) {
            $meta_key = sanitize_key((string) $key);

            if (
                array_key_exists($meta_key, $original_meta)
                && get_post_meta($post->ID, $meta_key, true) !== $original_meta[$meta_key]
            ) {
                return sprintf('meta field %s', $meta_key);
            }
        }

        return null;
    }
}
