<?php

/**
 * awpt/list-blocks ability.
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
 * Lists Gutenberg blocks in a post as a flat path index.
 */
final class ListBlocks implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/list-blocks',
            'label' => __('List Blocks', 'agent-wordpress-terminal'),
            'description' => __(
                'Lists Gutenberg blocks for a post as a flat path/name/attrs summary. Optional name filter (e.g. core/image).',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __('Post ID.', 'agent-wordpress-terminal'),
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional block name filter such as core/image.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'max' => [
                        'type' => 'integer',
                        'description' => __(
                            'Maximum blocks to return (default 100, max 500).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['id'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_read(array $input): bool {
        $post_id = (int) ($input['id'] ?? 0);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $post_id = (int) ($input['id'] ?? 0);
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        $max = (int) ($input['max'] ?? 100);
        $tree = BlockTree::from_content((string) $post->post_content);
        $blocks = $tree->flat_list('' !== $name ? $name : null, $max);

        return [
            'id' => $post_id,
            'count' => count($blocks),
            'total_named_blocks' => $tree->count(),
            'blocks' => $blocks,
            'path_format' => __('Dotted zero-based visible block path, e.g. 0 or 2.1.', 'agent-wordpress-terminal'),
        ];
    }
}
