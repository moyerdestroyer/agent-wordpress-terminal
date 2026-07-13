<?php

/**
 * awpt/list-templates ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists wp_template and wp_template_part posts for FSE themes.
 */
final class ListTemplates implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/list-templates',
            'label' => __('List Templates', 'agent-wordpress-terminal'),
            'description' => __(
                'Lists block theme templates and template parts (id, slug, type, status, theme).',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => __(
                            'wp_template, wp_template_part, or all (default all).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => __('Optional title/slug search.', 'agent-wordpress-terminal'),
                    ],
                    'max' => [
                        'type' => 'integer',
                        'description' => __('Maximum items (default 50, max 100).', 'agent-wordpress-terminal'),
                    ],
                ],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_list'],
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
    public function can_list(array $input): bool {
        return current_user_can('edit_theme_options') || current_user_can('edit_posts');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $type = sanitize_key((string) ($input['type'] ?? 'all'));
        $types = match ($type) {
            'wp_template' => ['wp_template'],
            'wp_template_part' => ['wp_template_part'],
            default => ['wp_template', 'wp_template_part'],
        };
        $max = max(1, min(100, (int) ($input['max'] ?? 50)));
        $search = sanitize_text_field((string) ($input['search'] ?? ''));
        $args = [
            'post_type' => $types,
            'post_status' => ['publish', 'draft', 'private', 'future'],
            'posts_per_page' => $max,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if ('' !== $search) {
            $args['s'] = $search;
        }

        $query = new \WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $items[] = [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'slug' => $post->post_name,
                'type' => $post->post_type,
                'status' => $post->post_status,
                'theme' => (string) get_post_meta($post->ID, 'theme', true),
                'area' => (string) get_post_meta($post->ID, 'area', true),
            ];
        }

        return [
            'count' => count($items),
            'templates' => $items,
        ];
    }
}
