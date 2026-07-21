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
            'input_schema' => ['type' => 'object'],
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
        unset($input);
        $theme = get_stylesheet();
        $posts = get_posts([
            'post_type' => 'wp_global_styles',
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => 20,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        foreach (is_array($posts) ? $posts : [] as $post) {
            if (!$post instanceof \WP_Post || !current_user_can('read_post', $post->ID)) {
                continue;
            }

            $stylesheet = (string) get_post_meta($post->ID, 'theme', true);

            if ('' !== $stylesheet && $stylesheet !== $theme) {
                continue;
            }

            return [
                'id' => $post->ID,
                'theme' => '' !== $stylesheet ? $stylesheet : $theme,
                'status' => $post->post_status,
                'content' => $post->post_content,
                'modified' => $post->post_modified,
            ];
        }

        return [
            'id' => 0,
            'theme' => $theme,
            'content' => '',
            'note' => 'No saved global-styles revision exists for the active theme.',
        ];
    }
}
