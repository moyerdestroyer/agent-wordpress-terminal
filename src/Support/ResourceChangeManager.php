<?php

/**
 * Validates and applies approval-gated changes to common WordPress resources.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

final class ResourceChangeManager {
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error
     */
    public function prepare(string $type, string $operation, string $id, array $data): array|\WP_Error {
        $type = sanitize_key($type);
        $operation = sanitize_key($operation);
        $id = sanitize_text_field($id);
        $data = $this->normalize_data($type, $operation, $data);

        if (is_wp_error($data)) {
            return $data;
        }

        $permission = $this->permission_error($type, $operation, $id, $data);

        if (null !== $permission) {
            return $permission;
        }

        $original = $this->snapshot($type, $id, $data);

        if (is_wp_error($original)) {
            return $original;
        }

        return [
            'resource_type' => $type,
            'resource_operation' => $operation,
            'resource_id' => $id,
            'resource_data' => $data,
            'resource_original' => $original,
            'resource_fingerprint' => $this->fingerprint($original),
            'affected' => $this->affected($type, $operation, $id, $data),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function can_apply(array $payload): bool {
        $data = is_array($payload['resource_data'] ?? null) ? $payload['resource_data'] : [];
        /** @var array<string, mixed> $data */

        return null === $this->permission_error(
            (string) ($payload['resource_type'] ?? ''),
            (string) ($payload['resource_operation'] ?? ''),
            (string) ($payload['resource_id'] ?? ''),
            $data,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<array-key, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error {
        $type = sanitize_key((string) ($payload['resource_type'] ?? ''));
        $operation = sanitize_key((string) ($payload['resource_operation'] ?? ''));
        $id = sanitize_text_field((string) ($payload['resource_id'] ?? ''));
        $data = is_array($payload['resource_data'] ?? null) ? $payload['resource_data'] : [];
        /** @var array<string, mixed> $data */
        $permission = $this->permission_error($type, $operation, $id, $data);

        if (null !== $permission) {
            return $permission;
        }

        $current = $this->snapshot($type, $id, $data);

        if (is_wp_error($current)) {
            return $current;
        }

        $expected = (string) ($payload['resource_fingerprint'] ?? '');

        if ('' !== $expected && !hash_equals($expected, $this->fingerprint($current))) {
            return new \WP_Error(
                'awpt_resource_conflict',
                __(
                    'The target resource changed after this proposal was staged. Read it again and restage.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409, 'resource_type' => $type, 'resource_id' => $id],
            );
        }

        /** @var array<string, mixed>|\WP_Error|null $filtered */
        $filtered = apply_filters('awpt_apply_wordpress_resource_change', null, $type, $operation, $id, $data);

        if (is_array($filtered) || is_wp_error($filtered)) {
            return $filtered;
        }

        return match ($type) {
            'term' => $this->apply_term($operation, $id, $data),
            'post_terms' => $this->apply_post_terms($id, $data),
            'menu' => $this->apply_menu($operation, $id, $data),
            'menu_item' => $this->apply_menu_item($operation, $id, $data),
            'menu_location' => $this->apply_menu_location($data),
            'user' => $this->apply_user($operation, $id, $data),
            'comment' => $this->apply_comment($operation, $id, $data),
            'widget_area' => $this->apply_widget_area($id, $data),
            'registered_setting' => $this->apply_registered_setting($operation, $id, $data),
            'post_meta' => $this->apply_post_meta($operation, $id, $data),
            default => new \WP_Error(
                'awpt_resource_type_unsupported',
                __('No native applier is registered for this resource type.', 'agent-wordpress-terminal'),
                ['status' => 400],
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error
     */
    private function normalize_data(string $type, string $operation, array $data): array|\WP_Error {
        $data = new ResourceValueSanitizer()->sanitize_object($data);

        /** @var array<string, mixed>|\WP_Error|null $filtered */
        $filtered = apply_filters('awpt_normalize_wordpress_resource_change', null, $type, $operation, $data);

        if (is_array($filtered) || is_wp_error($filtered)) {
            return $filtered;
        }

        $allowed = match ($type) {
            'term' => ['create', 'update', 'delete'],
            'post_terms' => ['set'],
            'menu' => ['create', 'update', 'delete'],
            'menu_item' => ['create', 'update', 'delete'],
            'menu_location' => ['assign'],
            'user' => ['create', 'update', 'delete'],
            'comment' => ['create', 'update', 'status', 'delete'],
            'widget_area' => ['set_widgets'],
            'registered_setting', 'post_meta' => ['update', 'delete'],
            default => [],
        };

        if (!in_array($operation, $allowed, true)) {
            return new \WP_Error(
                'awpt_resource_operation_unsupported',
                __('Unsupported operation for this WordPress resource.', 'agent-wordpress-terminal'),
                ['status' => 400, 'resource_type' => $type, 'operation' => $operation],
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function permission_error(string $type, string $operation, string $id, array $data): ?\WP_Error {
        $allowed = match ($type) {
            'term' => $this->can_term($operation, $data),
            'post_terms' => $this->can_post_terms((int) $id, $data),
            'menu', 'menu_item', 'menu_location', 'widget_area' => current_user_can('edit_theme_options'),
            'user' => $this->can_user($operation, (int) $id, $data),
            'comment' => $this->can_comment($operation, (int) $id),
            'registered_setting' => current_user_can('manage_options') && $this->registered_setting_exists($id),
            'post_meta' => $this->can_post_meta((int) $id, (string) ($data['key'] ?? '')),
            default => false,
        };

        return (
            $allowed
                ? null
                : new \WP_Error(
                    'awpt_resource_permission',
                    __('You do not have permission for this WordPress resource operation.', 'agent-wordpress-terminal'),
                    ['status' => 403, 'resource_type' => $type, 'operation' => $operation],
                )
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>|\WP_Error
     */
    private function snapshot(string $type, string $id, array $data): array|\WP_Error {
        /** @var array<string, mixed>|\WP_Error|null $filtered */
        $filtered = apply_filters('awpt_snapshot_wordpress_resource', null, $type, $id, $data);

        if (is_array($filtered) || is_wp_error($filtered)) {
            return $filtered;
        }

        return match ($type) {
            'term' => $this->snapshot_term($id, $data),
            'post_terms' => $this->snapshot_post_terms((int) $id, $data),
            'menu' => $this->snapshot_menu($id),
            'menu_item' => $this->snapshot_menu_item($id, $data),
            'menu_location' => ['locations' => get_nav_menu_locations()],
            'user' => $this->snapshot_user($id, $data),
            'comment' => $this->snapshot_comment($id, $data),
            'widget_area' => $this->snapshot_widget_area($id),
            'registered_setting' => ['name' => $id, 'value' => get_option($id, null)],
            'post_meta' => [
                'post_id' => (int) $id,
                'key' => (string) ($data['key'] ?? ''),
                'value' => get_post_meta((int) $id, (string) ($data['key'] ?? ''), false),
            ],
            default => [],
        };
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function snapshot_term(string $id, array $data): array|\WP_Error {
        if ('0' === $id || '' === $id) {
            return [];
        }

        $term = get_term((int) $id, sanitize_key((string) ($data['taxonomy'] ?? '')));

        return (
            $term instanceof \WP_Term
                ? [
                    'id' => $term->term_id,
                    'taxonomy' => $term->taxonomy,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description,
                    'parent' => $term->parent,
                ]
                : new \WP_Error('awpt_term_not_found', __('Term not found.', 'agent-wordpress-terminal'))
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function snapshot_post_terms(int $post_id, array $data): array|\WP_Error {
        $taxonomy = sanitize_key((string) ($data['taxonomy'] ?? ''));
        $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);

        return is_wp_error($terms) ? $terms : ['post_id' => $post_id, 'taxonomy' => $taxonomy, 'term_ids' => $terms];
    }

    /** @return array<string, mixed>|\WP_Error */
    private function snapshot_menu(string $id): array|\WP_Error {
        if ('0' === $id || '' === $id) {
            return [];
        }

        $menu = wp_get_nav_menu_object((int) $id);

        return (
            $menu instanceof \WP_Term
                ? ['id' => $menu->term_id, 'name' => $menu->name, 'description' => $menu->description]
                : new \WP_Error('awpt_menu_not_found', __('Menu not found.', 'agent-wordpress-terminal'))
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function snapshot_menu_item(string $id, array $data): array|\WP_Error {
        if ('0' === $id || '' === $id) {
            return ['menu_id' => (int) ($data['menu_id'] ?? 0)];
        }

        $item = get_post((int) $id);

        if (!$item instanceof \WP_Post || 'nav_menu_item' !== $item->post_type) {
            return new \WP_Error('awpt_menu_item_not_found', __('Menu item not found.', 'agent-wordpress-terminal'));
        }

        return [
            'id' => $item->ID,
            'title' => $item->post_title,
            'status' => $item->post_status,
            'menu_order' => $item->menu_order,
            'meta' => get_post_meta($item->ID),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function snapshot_user(string $id, array $data): array|\WP_Error {
        unset($data);

        if ('0' === $id || '' === $id) {
            return [];
        }

        $user = get_userdata((int) $id);

        return (
            $user instanceof \WP_User
                ? [
                    'id' => $user->ID,
                    'login' => $user->user_login,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name,
                    'url' => $user->user_url,
                    'roles' => $user->roles,
                ]
                : new \WP_Error('awpt_user_not_found', __('User not found.', 'agent-wordpress-terminal'))
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function snapshot_comment(string $id, array $data): array|\WP_Error {
        unset($data);

        if ('0' === $id || '' === $id) {
            return [];
        }

        $comment = get_comment((int) $id);

        return (
            $comment instanceof \WP_Comment
                ? [
                    'id' => (int) $comment->comment_ID,
                    'post_id' => (int) $comment->comment_post_ID,
                    'content' => $comment->comment_content,
                    'author' => $comment->comment_author,
                    'author_email' => $comment->comment_author_email,
                    'status' => wp_get_comment_status($comment),
                    'parent' => (int) $comment->comment_parent,
                ]
                : new \WP_Error('awpt_comment_not_found', __('Comment not found.', 'agent-wordpress-terminal'))
        );
    }

    /** @return array<string, mixed> */
    private function snapshot_widget_area(string $id): array {
        $sidebars = wp_get_sidebars_widgets();

        return ['id' => $id, 'widgets' => array_values(is_array($sidebars[$id] ?? null) ? $sidebars[$id] : [])];
    }

    /** @param array<string, mixed> $data */
    private function can_term(string $operation, array $data): bool {
        $taxonomy = get_taxonomy(sanitize_key((string) ($data['taxonomy'] ?? '')));

        if (!$taxonomy instanceof \WP_Taxonomy) {
            return false;
        }

        $cap = (string) ('delete' === $operation ? $taxonomy->cap->delete_terms : $taxonomy->cap->edit_terms);

        return current_user_can($cap);
    }

    /** @param array<string, mixed> $data */
    private function can_post_terms(int $post_id, array $data): bool {
        $taxonomy = get_taxonomy(sanitize_key((string) ($data['taxonomy'] ?? '')));

        return (
            $post_id > 0
            && $taxonomy instanceof \WP_Taxonomy
            && current_user_can('edit_post', $post_id)
            && current_user_can((string) $taxonomy->cap->assign_terms)
        );
    }

    /** @param array<string, mixed> $data */
    private function can_user(string $operation, int $id, array $data): bool {
        $role = sanitize_key((string) ($data['role'] ?? ''));
        $can_assign_role =
            '' === $role || current_user_can('promote_users') && array_key_exists($role, get_editable_roles());

        return match ($operation) {
            'create' => current_user_can('create_users') && $can_assign_role,
            'delete' => $id > 0 && current_user_can('delete_user', $id),
            default => $id > 0 && current_user_can('edit_user', $id) && $can_assign_role,
        };
    }

    private function can_comment(string $operation, int $id): bool {
        return 'create' === $operation
            ? current_user_can('edit_posts')
            : $id > 0 && (current_user_can('edit_comment', $id) || current_user_can('moderate_comments'));
    }

    private function registered_setting_exists(string $name): bool {
        global $wp_registered_settings;

        return (
            is_array($wp_registered_settings)
            && is_array($wp_registered_settings[$name] ?? null)
            && !$this->sensitive_key($name)
        );
    }

    private function can_post_meta(int $post_id, string $key): bool {
        if ($post_id <= 0 || '' === $key || $this->sensitive_key($key) || !current_user_can('edit_post', $post_id)) {
            return false;
        }

        if (new PostMetaKeyPolicy()->is_exposed($key)) {
            return current_user_can('edit_post_meta', $post_id, $key);
        }

        $post = get_post($post_id);
        $registered = $post instanceof \WP_Post && function_exists('get_registered_meta_keys')
            ? get_registered_meta_keys('post', $post->post_type)
            : [];

        return array_key_exists($key, $registered) && current_user_can('edit_post_meta', $post_id, $key);
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_term(string $operation, string $id, array $data): array|\WP_Error {
        $taxonomy = sanitize_key((string) ($data['taxonomy'] ?? ''));

        if ('create' === $operation) {
            $result = wp_insert_term(sanitize_text_field((string) ($data['name'] ?? '')), $taxonomy, [
                'slug' => sanitize_title((string) ($data['slug'] ?? '')),
                'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
                'parent' => (int) ($data['parent'] ?? 0),
            ]);

            return is_wp_error($result) ? $result : ['term_id' => (int) $result['term_id']];
        }

        if ('delete' === $operation) {
            $result = wp_delete_term((int) $id, $taxonomy);

            return is_wp_error($result) ? $result : ['term_id' => (int) $id, 'deleted' => (bool) $result];
        }

        $args = [];

        if (array_key_exists('name', $data)) {
            $args['name'] = sanitize_text_field((string) $data['name']);
        }

        if (array_key_exists('slug', $data)) {
            $args['slug'] = sanitize_title((string) $data['slug']);
        }

        if (array_key_exists('description', $data)) {
            $args['description'] = sanitize_textarea_field((string) $data['description']);
        }

        if (array_key_exists('parent', $data)) {
            $args['parent'] = (int) $data['parent'];
        }

        /** @var array{alias_of?: string, description?: string, parent?: int, slug?: string} $args */
        $result = wp_update_term((int) $id, $taxonomy, $args);

        return is_wp_error($result) ? $result : ['term_id' => (int) ($result['term_id'] ?? $id)];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_post_terms(string $id, array $data): array|\WP_Error {
        $terms = is_array($data['terms'] ?? null) ? $data['terms'] : [];
        $terms = array_map(static fn(mixed $term): int|string => is_numeric($term)
            ? (int) $term
            : sanitize_title((string) $term), $terms);
        $result = wp_set_object_terms(
            (int) $id,
            $terms,
            sanitize_key((string) ($data['taxonomy'] ?? '')),
            ArrayKey::rest_bool($data['append'] ?? false),
        );

        return is_wp_error($result) ? $result : ['post_id' => (int) $id, 'term_ids' => $result];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_menu(string $operation, string $id, array $data): array|\WP_Error {
        if ('create' === $operation) {
            $menu_id = wp_create_nav_menu(sanitize_text_field((string) ($data['name'] ?? '')));

            return is_wp_error($menu_id) ? $menu_id : ['menu_id' => (int) $menu_id];
        }

        if ('delete' === $operation) {
            $deleted = wp_delete_nav_menu((int) $id);

            return is_wp_error($deleted) ? $deleted : ['menu_id' => (int) $id, 'deleted' => $deleted];
        }

        $current = wp_get_nav_menu_object((int) $id);

        if (!$current instanceof \WP_Term) {
            return new \WP_Error('awpt_menu_not_found', __('Menu not found.', 'agent-wordpress-terminal'));
        }

        $result = wp_update_nav_menu_object((int) $id, [
            'menu-name' => array_key_exists('name', $data)
                ? sanitize_text_field((string) $data['name'])
                : $current->name,
            'description' => array_key_exists('description', $data)
                ? sanitize_textarea_field((string) $data['description'])
                : $current->description,
        ]);

        return is_wp_error($result) ? $result : ['menu_id' => (int) $result];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_menu_item(string $operation, string $id, array $data): array|\WP_Error {
        if ('delete' === $operation) {
            $deleted = wp_delete_post((int) $id, true);

            return (
                $deleted instanceof \WP_Post
                    ? ['menu_item_id' => (int) $id, 'deleted' => true]
                    : new \WP_Error('awpt_menu_item_delete_failed', __(
                        'Menu item could not be deleted.',
                        'agent-wordpress-terminal',
                    ))
            );
        }

        $menu_id = (int) ($data['menu_id'] ?? 0);
        $args = [
            'menu-item-title' => sanitize_text_field((string) ($data['title'] ?? '')),
            'menu-item-url' => esc_url_raw((string) ($data['url'] ?? '')),
            'menu-item-status' => sanitize_key((string) ($data['status'] ?? 'publish')),
            'menu-item-parent-id' => (int) ($data['parent'] ?? 0),
            'menu-item-position' => (int) ($data['position'] ?? 0),
            'menu-item-type' => sanitize_key((string) ($data['type'] ?? 'custom')),
            'menu-item-object' => sanitize_key((string) ($data['object'] ?? 'custom')),
            'menu-item-object-id' => (int) ($data['object_id'] ?? 0),
            'menu-item-target' => sanitize_text_field((string) ($data['target'] ?? '')),
            'menu-item-classes' => implode(' ', array_map(
                'sanitize_html_class',
                is_array($data['classes'] ?? null) ? $data['classes'] : [],
            )),
        ];
        $result = wp_update_nav_menu_item($menu_id, 'create' === $operation ? 0 : (int) $id, $args);

        return is_wp_error($result) ? $result : ['menu_item_id' => (int) $result, 'menu_id' => $menu_id];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_menu_location(array $data): array|\WP_Error {
        $location = sanitize_key((string) ($data['location'] ?? ''));
        $registered = get_registered_nav_menus();

        if ('' === $location || !array_key_exists($location, $registered)) {
            return new \WP_Error('awpt_menu_location_not_found', __(
                'Registered menu location not found.',
                'agent-wordpress-terminal',
            ));
        }

        $locations = get_nav_menu_locations();
        $locations[$location] = (int) ($data['menu_id'] ?? 0);
        set_theme_mod('nav_menu_locations', $locations);

        return ['location' => $location, 'menu_id' => $locations[$location]];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_user(string $operation, string $id, array $data): array|\WP_Error {
        if ('create' === $operation) {
            $password = wp_generate_password(24, true, true);
            $user_id = wp_insert_user([
                'user_login' => sanitize_user((string) ($data['login'] ?? ''), true),
                'user_email' => sanitize_email((string) ($data['email'] ?? '')),
                'display_name' => sanitize_text_field((string) ($data['display_name'] ?? '')),
                'first_name' => sanitize_text_field((string) ($data['first_name'] ?? '')),
                'last_name' => sanitize_text_field((string) ($data['last_name'] ?? '')),
                'user_url' => esc_url_raw((string) ($data['url'] ?? '')),
                'role' => sanitize_key((string) ($data['role'] ?? get_option('default_role', 'subscriber'))),
                'user_pass' => $password,
            ]);

            if (is_wp_error($user_id)) {
                return $user_id;
            }

            wp_new_user_notification((int) $user_id, null, 'user');

            return ['user_id' => (int) $user_id, 'password_delivery' => 'user_notification'];
        }

        if ('delete' === $operation) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $deleted = wp_delete_user((int) $id, (int) ($data['reassign'] ?? 0));

            return (
                $deleted
                    ? ['user_id' => (int) $id, 'deleted' => true]
                    : new \WP_Error('awpt_user_delete_failed', __(
                        'User could not be deleted.',
                        'agent-wordpress-terminal',
                    ))
            );
        }

        $update = ['ID' => (int) $id];

        foreach ([
            'email' => 'user_email',
            'display_name' => 'display_name',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'url' => 'user_url',
            'role' => 'role',
        ] as $source => $target) {
            if (!array_key_exists($source, $data)) {
                continue;
            }

            $update[$target] = 'email' === $source
                ? sanitize_email((string) $data[$source])
                : (
                    'url' === $source
                        ? esc_url_raw((string) $data[$source])
                        : (
                            'role' === $source
                                ? sanitize_key((string) $data[$source])
                                : sanitize_text_field((string) $data[$source])
                        )
                );
        }

        $result = wp_update_user($update);

        return is_wp_error($result) ? $result : ['user_id' => (int) $result];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_comment(string $operation, string $id, array $data): array|\WP_Error {
        if ('create' === $operation) {
            $comment_id = wp_insert_comment([
                'comment_post_ID' => (int) ($data['post_id'] ?? 0),
                'comment_parent' => (int) ($data['parent'] ?? 0),
                'comment_author' => sanitize_text_field((string) ($data['author'] ?? '')),
                'comment_author_email' => sanitize_email((string) ($data['author_email'] ?? '')),
                'comment_author_url' => esc_url_raw((string) ($data['author_url'] ?? '')),
                'comment_content' => wp_kses_post((string) ($data['content'] ?? '')),
                'comment_approved' => sanitize_key((string) ($data['status'] ?? '0')),
                'user_id' => (int) ($data['user_id'] ?? get_current_user_id()),
            ]);

            return (
                false !== $comment_id && $comment_id > 0
                    ? ['comment_id' => $comment_id]
                    : new \WP_Error('awpt_comment_create_failed', __(
                        'Comment could not be created.',
                        'agent-wordpress-terminal',
                    ))
            );
        }

        if ('delete' === $operation) {
            $deleted = wp_delete_comment((int) $id, true);

            return (
                $deleted
                    ? ['comment_id' => (int) $id, 'deleted' => true]
                    : new \WP_Error('awpt_comment_delete_failed', __(
                        'Comment could not be deleted.',
                        'agent-wordpress-terminal',
                    ))
            );
        }

        if ('status' === $operation) {
            $status = sanitize_key((string) ($data['status'] ?? 'hold'));
            $status = in_array($status, ['approve', 'hold', 'spam', 'trash'], true) ? $status : 'hold';
            $updated = wp_set_comment_status((int) $id, $status, true);

            return (
                true === $updated
                    ? ['comment_id' => (int) $id, 'status' => (string) ($data['status'] ?? '')]
                    : new \WP_Error('awpt_comment_status_failed', __(
                        'Comment status could not be changed.',
                        'agent-wordpress-terminal',
                    ))
            );
        }

        $update = ['comment_ID' => (int) $id];

        foreach ([
            'post_id' => 'comment_post_ID',
            'parent' => 'comment_parent',
            'author' => 'comment_author',
            'author_email' => 'comment_author_email',
            'author_url' => 'comment_author_url',
            'content' => 'comment_content',
        ] as $source => $target) {
            if (!array_key_exists($source, $data)) {
                continue;
            }

            $update[$target] = $data[$source];
        }

        $result = wp_update_comment(wp_slash($update), true);

        return is_wp_error($result) ? $result : ['comment_id' => (int) $id];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_widget_area(string $id, array $data): array|\WP_Error {
        global $wp_registered_sidebars, $wp_registered_widgets;

        if (!is_array($wp_registered_sidebars) || !array_key_exists($id, $wp_registered_sidebars)) {
            return new \WP_Error('awpt_widget_area_not_found', __(
                'Widget area not found.',
                'agent-wordpress-terminal',
            ));
        }

        $requested = is_array($data['widgets'] ?? null) ? array_values(array_map('strval', $data['widgets'])) : [];

        foreach ($requested as $widget_id) {
            if (!is_array($wp_registered_widgets) || !array_key_exists($widget_id, $wp_registered_widgets)) {
                return new \WP_Error('awpt_widget_not_registered', sprintf(
                    __('Widget is not registered: %s.', 'agent-wordpress-terminal'),
                    $widget_id,
                ));
            }
        }

        $sidebars = wp_get_sidebars_widgets();

        foreach ($sidebars as $sidebar_id => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }

            $sidebars[$sidebar_id] = array_values(array_diff($widgets, $requested));
        }

        $sidebars[$id] = $requested;
        wp_set_sidebars_widgets($sidebars);

        return ['widget_area' => $id, 'widgets' => $requested];
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
    private function apply_registered_setting(string $operation, string $id, array $data): array|\WP_Error {
        if ('delete' === $operation) {
            return ['setting' => $id, 'deleted' => delete_option($id)];
        }

        $value = $data['value'] ?? null;
        $value = sanitize_option($id, $value);
        $updated = update_option($id, $value);

        return ['setting' => $id, 'updated' => $updated, 'value' => get_option($id)];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function apply_post_meta(string $operation, string $id, array $data): array {
        $post_id = (int) $id;
        $key = (string) ($data['key'] ?? '');

        if ('delete' === $operation) {
            return ['post_id' => $post_id, 'key' => $key, 'deleted' => delete_post_meta($post_id, $key)];
        }

        $value = $data['value'] ?? null;
        $updated = update_post_meta($post_id, $key, $value);

        return ['post_id' => $post_id, 'key' => $key, 'updated' => false !== $updated];
    }

    /** @param array<string, mixed> $data */
    private function affected(string $type, string $operation, string $id, array $data): string {
        $label = (string) ($data['name'] ?? $data['title'] ?? $data['key'] ?? $data['location'] ?? $id);

        return trim(sprintf('%s %s %s', $operation, $type, $label));
    }

    /** @param array<array-key, mixed> $snapshot */
    private function fingerprint(array $snapshot): string {
        return hash('sha256', (string) wp_json_encode($snapshot));
    }

    private function sensitive_key(string $key): bool {
        return (bool) preg_match('/password|secret|token|api[_-]?key|credential|private[_-]?key|license/i', $key);
    }
}
