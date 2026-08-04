<?php

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/** Reads both block-theme Navigation entities and classic menus. */
final class ReadNavigation implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-navigation',
            'label' => __('Read Navigation', 'agent-wordpress-terminal'),
            'description' => __(
                'Lists block-theme Navigation entities and classic menus with their exact IDs. Pass navigation_id or menu_id to inspect one target before proposing a change.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'navigation_id' => ['type' => 'integer'],
                    'menu_id' => ['type' => 'integer'],
                ],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_theme_options'),
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $navigation_id = (int) ($input['navigation_id'] ?? 0);
        $menu_id = (int) ($input['menu_id'] ?? 0);

        if ($navigation_id > 0) {
            $post = get_post($navigation_id);

            return $post instanceof \WP_Post && 'wp_navigation' === $post->post_type
                ? $this->block_navigation($post)
                : new \WP_Error('awpt_navigation_not_found', __(
                    'Navigation entity not found.',
                    'agent-wordpress-terminal',
                ));
        }

        if ($menu_id > 0) {
            $menu = wp_get_nav_menu_object($menu_id);

            if (!$menu instanceof \WP_Term) {
                return new \WP_Error('awpt_menu_not_found', __('Classic menu not found.', 'agent-wordpress-terminal'));
            }

            $items = wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);

            return [
                'mode' => 'classic',
                'id' => $menu->term_id,
                'name' => $menu->name,
                'description' => $menu->description,
                'items' => is_array($items) ? array_map($this->menu_item(...), $items) : [],
            ];
        }

        $block_items = [];

        foreach (get_posts([
            'post_type' => 'wp_navigation',
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
        ]) as $post) {
            if (!$post instanceof \WP_Post || !current_user_can('read_post', $post->ID)) {
                continue;
            }

            $block_items[] = $this->block_navigation_summary($post);
        }

        $classic_items = [];

        foreach (wp_get_nav_menus() as $menu) {
            if (!$menu instanceof \WP_Term) {
                continue;
            }

            $classic_items[] = [
                'id' => $menu->term_id,
                'name' => $menu->name,
                'description' => $menu->description,
                'count' => $menu->count,
            ];
        }

        return [
            'block_navigation' => $block_items,
            'classic_menus' => $classic_items,
            'agent_feedback' => [
                'outcome' => 'ready',
                'message' => __('Read the exact navigation target before staging changes.', 'agent-wordpress-terminal'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function block_navigation_summary(\WP_Post $post): array {
        return [
            'mode' => 'block',
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'modified' => $post->post_modified,
        ];
    }

    /** @return array<string, mixed> */
    private function block_navigation(\WP_Post $post): array {
        return [...$this->block_navigation_summary($post), 'content' => $post->post_content];
    }

    /** @return array<string, mixed> */
    private function menu_item(\WP_Post $item): array {
        $data = get_object_vars($item);

        return [
            'id' => $item->ID,
            'title' => (string) ($data['title'] ?? $item->post_title),
            'url' => (string) ($data['url'] ?? ''),
            'parent' => (int) ($data['menu_item_parent'] ?? 0),
            'order' => $item->menu_order,
            'type' => (string) ($data['type'] ?? ''),
            'object' => (string) ($data['object'] ?? ''),
            'object_id' => (int) ($data['object_id'] ?? 0),
        ];
    }
}
