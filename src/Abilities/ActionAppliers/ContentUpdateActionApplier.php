<?php

/**
 * Applies staged post content updates.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Applies a staged post/page content update action.
 */
final class ContentUpdateActionApplier
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error
    {
        $post_id = (int) ($payload['post_id'] ?? 0);

        if ($post_id <= 0 || !current_user_can(capability: 'edit_post', args: $post_id)) {
            return new \WP_Error(code: 'awpt_cannot_edit_post', message: __(
                'You do not have permission to edit this post.',
                'agent-wordpress-terminal',
            ));
        }

        $update = ['ID' => $post_id];

        if (array_key_exists('post_title', $payload)) {
            $update['post_title'] = sanitize_text_field((string) $payload['post_title']);
        }

        if (array_key_exists('post_content', $payload)) {
            $update['post_content'] = wp_kses_post((string) $payload['post_content']);
        }

        if (1 === count($update)) {
            return new \WP_Error(code: 'awpt_empty_action', message: __(
                'Action has no post changes to apply.',
                'agent-wordpress-terminal',
            ));
        }

        $updated = wp_update_post($update, wp_error: true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return ['post_id' => $post_id];
    }
}
