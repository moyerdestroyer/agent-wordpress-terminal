<?php

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

use AWPT\Support\PostContentSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/** Creates the active theme's first user global-styles revision after approval. */
final class GlobalStylesCreateActionApplier {
    /** @param array<string, mixed> $payload @return array<string, mixed>|\WP_Error */
    public function apply(array $payload): array|\WP_Error {
        if (!current_user_can('edit_theme_options')) {
            return new \WP_Error(
                'awpt_cannot_edit_global_styles',
                __('You do not have permission to edit global styles.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $content = trim((string) ($payload['post_content'] ?? ''));

        if ('' === $content || !is_array(json_decode($content, true))) {
            return new \WP_Error(
                'awpt_invalid_global_styles',
                __('Global styles content must be a non-empty JSON object.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $theme = get_stylesheet();
        $post_id = wp_insert_post([
            'post_type' => 'wp_global_styles',
            'post_status' => 'publish',
            'post_title' => sprintf(__('Global Styles: %s', 'agent-wordpress-terminal'), $theme),
            'post_name' => 'wp-global-styles-' . sanitize_title($theme),
            'post_content' => PostContentSanitizer::for_staged_update($content),
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta((int) $post_id, 'theme', $theme);

        return ['post_id' => (int) $post_id];
    }
}
