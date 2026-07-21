<?php

/**
 * awpt/read-template ability.
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
 * Reads a template or template part with block tree summary.
 */
final class ReadTemplate implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-template',
            'label' => __('Read Template', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads a wp_template or wp_template_part by ID, including content and block tree summary.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __('Template post ID.', 'agent-wordpress-terminal'),
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

        if (!$post instanceof \WP_Post || !in_array($post->post_type, ['wp_template', 'wp_template_part'], true)) {
            return new \WP_Error('awpt_template_not_found', __('Template not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $tree = BlockTree::from_content($post->post_content);

        return [
            'id' => $post_id,
            'title' => get_the_title($post),
            'slug' => $post->post_name,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'theme' => (string) get_post_meta($post->ID, 'theme', true),
            'area' => (string) get_post_meta($post->ID, 'area', true),
            'content' => $post->post_content,
            'block_count' => $tree->count(),
            'blocks' => $tree->flat_list(null, 80),
        ];
    }
}
