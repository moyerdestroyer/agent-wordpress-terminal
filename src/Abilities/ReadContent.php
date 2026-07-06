<?php

/**
 * awpt/read-content ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\ReadablePostMeta;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns readable post/page content for agent analysis.
 */
final class ReadContent
{
    private ReadablePostMeta $meta;

    public function __construct(?ReadablePostMeta $meta = null)
    {
        $this->meta = $meta ?? new ReadablePostMeta();
    }

    /**
     * Register the ability.
     */
    public function register(): void
    {
        wp_register_ability('awpt/read-content', [
            'label' => __('Read Content', 'agent-wordpress-terminal'),
            'description' => __('Returns readable post or page content and metadata.', 'agent-wordpress-terminal'),
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
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'plain_text' => ['type' => 'string'],
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
    public function can_read(array $input): bool
    {
        $post_id = (int) ($input['id'] ?? 0);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error
    {
        $post_id = (int) ($input['id'] ?? 0);
        $post = get_post($post_id);

        if (!$post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        return [
            'id' => $post_id,
            'title' => get_the_title($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'url' => get_permalink($post),
            'content' => (string) $post->post_content,
            'plain_text' => wp_strip_all_tags((string) $post->post_content),
            'excerpt' => wp_strip_all_tags((string) $post->post_excerpt),
            'modified' => $post->post_modified_gmt,
            'author_id' => (int) $post->post_author,
            'meta' => $this->meta->for_post($post_id),
        ];
    }
}
