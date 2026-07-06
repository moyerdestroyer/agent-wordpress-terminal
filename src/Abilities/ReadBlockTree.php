<?php

/**
 * awpt/read-block-tree ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\BlockTree;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns parsed Gutenberg block structure for a post.
 */
final class ReadBlockTree {
    /**
     * Register the ability.
     */
    public function register(): void {
        wp_register_ability('awpt/read-block-tree', [
            'label' => __('Read Block Tree', 'agent-wordpress-terminal'),
            'description' => __('Returns parsed Gutenberg block structure for a post.', 'agent-wordpress-terminal'),
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
                'properties' => [
                    'blocks' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
                    'path_format' => ['type' => 'string'],
                ],
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

        $tree = BlockTree::from_content((string) $post->post_content);

        return [
            'blocks' => $tree->normalized(),
            'count' => $tree->count(),
            'path_format' => __('Dotted zero-based visible block path, e.g. 0 or 2.1.', 'agent-wordpress-terminal'),
        ];
    }
}
