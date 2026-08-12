<?php

/**
 * Read-only inventory for common WordPress resources outside post content.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

final class WordPressResourceReader {
    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        $type = sanitize_key((string) ($input['resource_type'] ?? ''));

        return match ($type) {
            'taxonomies', 'terms', 'term', 'post_meta' => current_user_can(
                'read_post',
                (int) ($input['post_id'] ?? $input['resource_id'] ?? 0),
            ) || current_user_can('edit_posts'),
            'menus', 'menu', 'menu_items', 'menu_item', 'widget_areas' => current_user_can('edit_theme_options'),
            'users', 'user' => current_user_can('list_users'),
            'comments', 'comment' => current_user_can('moderate_comments'),
            'registered_settings', 'registered_setting' => current_user_can('manage_options'),
            default => (bool) apply_filters('awpt_can_read_wordpress_resource', false, $type, $input),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function list(array $input): array|\WP_Error {
        if (!$this->can_read($input)) {
            return new \WP_Error(
                'awpt_resource_read_permission',
                __('You do not have permission to read this WordPress resource.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $type = sanitize_key((string) ($input['resource_type'] ?? ''));
        $limit = max(1, min(100, (int) ($input['limit'] ?? 30)));
        $search = sanitize_text_field((string) ($input['search'] ?? ''));

        /** @var list<array<string, mixed>>|\WP_Error|null $result */
        $result = match ($type) {
            'taxonomies' => $this->taxonomies(),
            'terms' => $this->terms(sanitize_key((string) ($input['taxonomy'] ?? '')), $search, $limit),
            'menus' => $this->menus(),
            'menu_items' => $this->menu_items((int) ($input['menu_id'] ?? 0)),
            'users' => $this->users($search, $limit),
            'comments' => $this->comments($input, $limit),
            'widget_areas' => $this->widget_areas(),
            'registered_settings' => $this->registered_settings($search, $limit),
            'post_meta' => $this->post_meta((int) ($input['post_id'] ?? 0)),
            default => apply_filters('awpt_list_wordpress_resource', null, $type, $input),
        };

        if (null === $result) {
            return new \WP_Error(
                'awpt_resource_type_unsupported',
                __(
                    'Unsupported resource type. Inspect the ability description or connected resource tools.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 400, 'resource_type' => $type],
            );
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'resource_type' => $type,
            'items' => array_values($result),
            'count' => count($result),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function read(array $input): array|\WP_Error {
        if (!$this->can_read($input)) {
            return new \WP_Error(
                'awpt_resource_read_permission',
                __('You do not have permission to read this WordPress resource.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $type = sanitize_key((string) ($input['resource_type'] ?? ''));
        $id = sanitize_text_field((string) ($input['resource_id'] ?? ''));
        $context = $input;
        $context['limit'] = 100;

        if ('term' === $type) {
            $taxonomy = sanitize_key((string) ($input['taxonomy'] ?? ''));
            $term = get_term((int) $id, $taxonomy);

            return $this->term_result($term, $taxonomy);
        }

        if ('menu' === $type) {
            $menu = wp_get_nav_menu_object((int) $id);

            return $menu instanceof \WP_Term
                ? $this->menu_row($menu)
                + [
                    'items' => $this->menu_items($menu->term_id),
                ]
                : $this->not_found($type);
        }

        if ('menu_item' === $type) {
            $post = get_post((int) $id);

            return $post instanceof \WP_Post && 'nav_menu_item' === $post->post_type
                ? $this->menu_item_row($post)
                : $this->not_found($type);
        }

        if ('user' === $type) {
            $user = get_userdata((int) $id);

            return $user instanceof \WP_User ? $this->user_row($user) : $this->not_found($type);
        }

        if ('comment' === $type) {
            $comment = get_comment((int) $id);

            return $comment instanceof \WP_Comment ? $this->comment_row($comment) : $this->not_found($type);
        }

        if ('registered_setting' === $type) {
            return $this->registered_setting($id);
        }

        if ('post_meta' === $type) {
            $context['post_id'] = (int) ($input['post_id'] ?? $id);
            $listed = $this->list(['resource_type' => 'post_meta', ...$context]);

            return (
                is_wp_error($listed)
                    ? $listed
                    : [
                        'resource_type' => 'post_meta',
                        'post_id' => (int) $context['post_id'],
                        'items' => $listed['items'],
                    ]
            );
        }

        /** @var array<string, mixed>|\WP_Error|null $filtered */
        $filtered = apply_filters('awpt_read_wordpress_resource', null, $type, $id, $input);

        return is_array($filtered) || is_wp_error($filtered) ? $filtered : $this->not_found($type);
    }

    /** @return list<array<string, mixed>> */
    private function taxonomies(): array {
        $items = [];

        foreach (get_taxonomies(['show_ui' => true], 'objects') as $taxonomy) {
            if (!$taxonomy instanceof \WP_Taxonomy) {
                continue;
            }

            $items[] = [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label,
                'hierarchical' => $taxonomy->hierarchical,
                'object_types' => array_values($taxonomy->object_type),
                'capabilities' => (array) $taxonomy->cap,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>|\WP_Error
     */
    private function terms(string $taxonomy, string $search, int $limit): array|\WP_Error {
        if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
            return new \WP_Error('awpt_taxonomy_not_found', __(
                'Provide an installed taxonomy.',
                'agent-wordpress-terminal',
            ));
        }

        /** @var list<\WP_Term>|\WP_Error $terms */
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => $limit,
            'search' => $search,
        ]);

        if (is_wp_error($terms)) {
            return $terms;
        }

        return array_values(array_map($this->term_row(...), $terms));
    }

    /** @return array<string, mixed>|\WP_Error */
    private function term_result(mixed $term, string $taxonomy): array|\WP_Error {
        if (is_wp_error($term)) {
            return $term;
        }

        return $term instanceof \WP_Term && ('' === $taxonomy || $term->taxonomy === $taxonomy)
            ? $this->term_row($term)
            : $this->not_found('term');
    }

    /** @return array<string, mixed> */
    private function term_row(\WP_Term $term): array {
        return [
            'id' => $term->term_id,
            'taxonomy' => $term->taxonomy,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent' => $term->parent,
            'count' => $term->count,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function menus(): array {
        return array_values(array_map($this->menu_row(...), wp_get_nav_menus()));
    }

    /** @return array<string, mixed> */
    private function menu_row(\WP_Term $menu): array {
        return [
            'id' => $menu->term_id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'description' => $menu->description,
            'count' => $menu->count,
            'locations' => array_keys(array_filter(
                get_nav_menu_locations(),
                static fn(int $menu_id): bool => $menu_id === $menu->term_id,
            )),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function menu_items(int $menu_id): array {
        if ($menu_id <= 0) {
            return [];
        }

        $items = wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);

        if (!is_array($items)) {
            return [];
        }

        return array_values(array_map($this->menu_item_row(...), $items));
    }

    /** @return array<string, mixed> */
    private function menu_item_row(\WP_Post $item): array {
        $attributes = get_object_vars($item);

        return [
            'id' => $item->ID,
            'title' => (string) ($attributes['title'] ?? $item->post_title),
            'url' => (string) ($attributes['url'] ?? ''),
            'menu_order' => $item->menu_order,
            'parent' => (int) ($attributes['menu_item_parent'] ?? 0),
            'type' => (string) ($attributes['type'] ?? ''),
            'object' => (string) ($attributes['object'] ?? ''),
            'object_id' => (int) ($attributes['object_id'] ?? 0),
            'target' => (string) ($attributes['target'] ?? ''),
            'classes' => is_array($attributes['classes'] ?? null) ? $attributes['classes'] : [],
            'status' => $item->post_status,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function users(string $search, int $limit): array {
        $args = ['number' => $limit, 'orderby' => 'display_name', 'order' => 'ASC'];

        if ('' !== $search) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
        }

        return array_values(array_map($this->user_row(...), get_users($args)));
    }

    /** @return array<string, mixed> */
    private function user_row(\WP_User $user): array {
        return [
            'id' => $user->ID,
            'login' => $user->user_login,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'url' => $user->user_url,
            'roles' => array_values($user->roles),
            'registered' => $user->user_registered,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function comments(array $input, int $limit): array {
        $args = [
            'number' => $limit,
            'status' => sanitize_key((string) ($input['status'] ?? 'all')),
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
        ];
        $post_id = (int) ($input['post_id'] ?? 0);

        if ($post_id > 0) {
            $args['post_id'] = $post_id;
        }

        $comments = get_comments($args);

        if (!is_array($comments)) {
            return [];
        }

        $items = [];

        foreach ($comments as $comment) {
            if (!$comment instanceof \WP_Comment) {
                continue;
            }

            $items[] = $this->comment_row($comment);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function comment_row(\WP_Comment $comment): array {
        return [
            'id' => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'parent' => (int) $comment->comment_parent,
            'author' => $comment->comment_author,
            'author_email' => $comment->comment_author_email,
            'author_url' => $comment->comment_author_url,
            'content' => $comment->comment_content,
            'status' => wp_get_comment_status($comment),
            'date_gmt' => $comment->comment_date_gmt,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function widget_areas(): array {
        global $wp_registered_sidebars;

        $assignments = wp_get_sidebars_widgets();
        $items = [];

        foreach (is_array($wp_registered_sidebars) ? $wp_registered_sidebars : [] as $id => $sidebar) {
            $items[] = [
                'id' => (string) $id,
                'name' => (string) ($sidebar['name'] ?? $id),
                'description' => (string) ($sidebar['description'] ?? ''),
                'widgets' => array_values(is_array($assignments[$id] ?? null) ? $assignments[$id] : []),
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function registered_settings(string $search, int $limit): array {
        global $wp_registered_settings;

        $items = [];

        foreach (is_array($wp_registered_settings) ? $wp_registered_settings : [] as $name => $setting) {
            if (
                count($items) >= $limit
                || '' !== $search && !str_contains(strtolower((string) $name), strtolower($search))
                || $this->sensitive_key((string) $name)
            ) {
                continue;
            }

            /** @var array<string, mixed> $setting */
            $items[] = $this->setting_row((string) $name, $setting);
        }

        return $items;
    }

    /** @return array<string, mixed>|\WP_Error */
    private function registered_setting(string $name): array|\WP_Error {
        global $wp_registered_settings;
        $setting = is_array($wp_registered_settings) ? $wp_registered_settings[$name] ?? null : null;

        if (!is_array($setting) || $this->sensitive_key($name)) {
            return $this->not_found('registered_setting');
        }

        /** @var array<string, mixed> $setting */
        return $this->setting_row($name, $setting);
    }

    /**
     * @param array<string, mixed> $setting
     * @return array<string, mixed>
     */
    private function setting_row(string $name, array $setting): array {
        return [
            'name' => $name,
            'type' => (string) ($setting['type'] ?? 'string'),
            'description' => (string) ($setting['description'] ?? ''),
            'default' => $setting['default'] ?? null,
            'value' => get_option($name, $setting['default'] ?? null),
            'show_in_rest' => false !== ($setting['show_in_rest'] ?? false),
        ];
    }

    /** @return list<array<string, mixed>>|\WP_Error */
    private function post_meta(int $post_id): array|\WP_Error {
        if ($post_id <= 0 || !current_user_can('read_post', $post_id)) {
            return new \WP_Error('awpt_post_not_found', __(
                'Readable post_id is required.',
                'agent-wordpress-terminal',
            ));
        }

        $items = [];

        foreach (new ReadablePostMeta()->for_post($post_id) as $key => $value) {
            $items[] = ['key' => $key, 'value' => $value, 'registered' => false];
        }

        $post = get_post($post_id);

        if ($post instanceof \WP_Post && function_exists('get_registered_meta_keys')) {
            foreach (get_registered_meta_keys('post', $post->post_type) as $key => $registration) {
                $key = (string) $key;

                if (array_key_exists($key, array_column($items, 'value', 'key')) || $this->sensitive_key($key)) {
                    continue;
                }

                $items[] = [
                    'key' => $key,
                    'value' => get_post_meta($post_id, $key, false),
                    'registered' => true,
                    'type' => (string) ($registration['type'] ?? 'string'),
                    'single' => (bool) ($registration['single'] ?? false),
                    'show_in_rest' => false !== ($registration['show_in_rest'] ?? false),
                ];
            }
        }

        return $items;
    }

    private function sensitive_key(string $key): bool {
        return (bool) preg_match('/password|secret|token|api[_-]?key|credential|private[_-]?key|license/i', $key);
    }

    private function not_found(string $type): \WP_Error {
        return new \WP_Error(
            'awpt_resource_not_found',
            sprintf(__('WordPress resource not found: %s.', 'agent-wordpress-terminal'), $type),
            ['status' => 404],
        );
    }
}
