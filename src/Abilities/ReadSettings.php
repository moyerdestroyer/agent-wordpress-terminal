<?php

/**
 * awpt/read-settings ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns non-secret site settings for agent analysis.
 */
final class ReadSettings {
    /**
     * Register the ability.
     */
    public function register(): void {
        wp_register_ability('awpt/read-settings', [
            'label' => __('Read Settings', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns non-secret WordPress site settings and environment details.',
                'agent-wordpress-terminal',
            ),
            'category' => 'awpt',
            'input_schema' => AbilitySchemas::empty_object_input(),
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'site' => ['type' => 'object'],
                    'reading' => ['type' => 'object'],
                    'permalinks' => ['type' => 'object'],
                    'theme' => ['type' => 'object'],
                    'discussion' => ['type' => 'object'],
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
        return current_user_can('manage_options');
    }

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $front_page_id = (int) get_option('page_on_front', 0);
        $posts_page_id = (int) get_option('page_for_posts', 0);
        $theme = wp_get_theme();

        return [
            'site' => [
                'name' => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'url' => home_url('/'),
                'admin_url' => admin_url(),
                'language' => get_bloginfo('language'),
                'timezone' => wp_timezone_string(),
                'wordpress_version' => get_bloginfo('version'),
            ],
            'reading' => [
                'show_on_front' => get_option('show_on_front', 'posts'),
                'front_page_id' => $front_page_id,
                'front_page_title' => $front_page_id > 0 ? get_the_title($front_page_id) : '',
                'posts_page_id' => $posts_page_id,
                'posts_page_title' => $posts_page_id > 0 ? get_the_title($posts_page_id) : '',
                'posts_per_page' => (int) get_option('posts_per_page', 10),
                'blog_public' => (int) get_option('blog_public', 1),
            ],
            'permalinks' => [
                'structure' => (string) get_option('permalink_structure', ''),
                'category_base' => (string) get_option('category_base', ''),
                'tag_base' => (string) get_option('tag_base', ''),
            ],
            'theme' => [
                'name' => $theme->get('Name'),
                'stylesheet' => get_stylesheet(),
                'template' => get_template(),
                'version' => $theme->get('Version'),
            ],
            'discussion' => [
                'default_comment_status' => (string) get_option('default_comment_status', 'closed'),
                'default_ping_status' => (string) get_option('default_ping_status', 'closed'),
                'require_name_email' => (bool) get_option('require_name_email', 1),
                'comment_registration' => (bool) get_option('comment_registration', 0),
                'close_comments_days_old' => (int) get_option('close_comments_for_old_posts', 14),
                'thread_comments' => (bool) get_option('thread_comments', 1),
                'comments_per_page' => (int) get_option('comments_per_page', 50),
                'page_comments' => (bool) get_option('page_comments', 0),
                'comment_moderation' => (bool) get_option('comment_moderation', 0),
            ],
        ];
    }
}
