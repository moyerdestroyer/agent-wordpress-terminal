<?php

/**
 * awpt/preview-post ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns preview URL and iframe metadata.
 */
final class PreviewPost implements AbilityInterface {
    /**
     * Register the ability.
     */
    public function register(): void {
        wp_register_ability('awpt/preview-post', [
            'label' => __('Preview Post', 'agent-wordpress-terminal'),
            'description' => __('Returns preview URL and iframe metadata for a post.', 'agent-wordpress-terminal'),
            'category' => 'awpt',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __('Post ID.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['id'],
            ],
            'output_schema' => [
                'type' => 'object',
            ],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                ],
            ],
        ]);
    }

    /**
     * Permission check.
     *
     * @param array<string, mixed> $input Ability input.
     */
    public function can_read(array $input): bool {
        $post_id = (int) ($input['id'] ?? 0);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $post_id = (int) ($input['id'] ?? 0);
        $post = get_post($post_id);

        if (!$post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        $preview_url = get_preview_post_link($post);

        if (!$preview_url) {
            $preview_url = get_permalink($post);
        }

        return [
            'id' => $post_id,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'preview_url' => $preview_url,
            'iframe' => [
                'src' => $preview_url,
                'title' => get_the_title($post),
                'height' => 640,
            ],
        ];
    }
}
