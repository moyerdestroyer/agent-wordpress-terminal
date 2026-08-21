<?php

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/** Reads the active theme's user global-styles revision when one exists. */
final class ReadGlobalStyles implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-global-styles',
            'label' => __('Read Global Styles', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads the active theme’s saved WordPress global-styles content and metadata.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sections' => [
                        'type' => 'array',
                        'items' => ['enum' => ['saved_content', 'resolved_settings', 'resolved_styles']],
                        'maxItems' => 3,
                    ],
                ],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        unset($input);
        return current_user_can('edit_theme_options');
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $sections = is_array($input['sections'] ?? null)
            ? array_values(array_intersect(
                ['saved_content', 'resolved_settings', 'resolved_styles'],
                array_map('strval', $input['sections']),
            ))
            : [];
        $theme = get_stylesheet();
        $posts = get_posts([
            'post_type' => 'wp_global_styles',
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => 20,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post || !current_user_can('read_post', $post->ID)) {
                continue;
            }

            $stylesheet = (string) get_post_meta($post->ID, 'theme', true);

            if ('' !== $stylesheet && $stylesheet !== $theme) {
                continue;
            }

            $result = [
                'id' => $post->ID,
                'theme' => '' !== $stylesheet ? $stylesheet : $theme,
                'status' => $post->post_status,
                'content_hash' => hash('sha256', $post->post_content),
                'modified' => $post->post_modified,
                'available_sections' => ['saved_content', 'resolved_settings', 'resolved_styles'],
            ];

            if (in_array('saved_content', $sections, true)) {
                $result['content'] = $post->post_content;
            }

            if (in_array('resolved_settings', $sections, true) && function_exists('wp_get_global_settings')) {
                $result['resolved_settings'] = wp_get_global_settings();
            }

            if (in_array('resolved_styles', $sections, true) && function_exists('wp_get_global_styles')) {
                $result['resolved_styles'] = wp_get_global_styles();
            }

            return $result;
        }

        $result = [
            'id' => 0,
            'theme' => $theme,
            'content' => '',
            'content_hash' => hash('sha256', ''),
            'available_sections' => ['saved_content', 'resolved_settings', 'resolved_styles'],
            'note' => 'No saved global-styles revision exists for the active theme.',
        ];

        if (in_array('resolved_settings', $sections, true) && function_exists('wp_get_global_settings')) {
            $result['resolved_settings'] = wp_get_global_settings();
        }

        if (in_array('resolved_styles', $sections, true) && function_exists('wp_get_global_styles')) {
            $result['resolved_styles'] = wp_get_global_styles();
        }

        return $result;
    }
}
