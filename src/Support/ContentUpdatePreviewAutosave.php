<?php

/**
 * Autosave helpers for staged content-update previews.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates and cleans up preview autosaves for existing posts.
 */
final class ContentUpdatePreviewAutosave {
    /**
     * @param array<array-key, mixed> $payload
     */
    public function discard(array $payload): void {
        $autosave_id = (int) ($payload['preview_autosave_id'] ?? 0);

        if ($autosave_id <= 0 || !get_post($autosave_id) instanceof \WP_Post) {
            return;
        }

        wp_delete_post($autosave_id, true);
    }

    /**
     * @param array<string, mixed> $autosave
     * @param array<string, mixed> $payload
     * @return int|\WP_Error
     */
    public function create(array $autosave, array $payload): int|\WP_Error {
        if (!function_exists('wp_create_post_autosave')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $autosave_id = wp_create_post_autosave(wp_slash($autosave));

        if (is_wp_error($autosave_id)) {
            return $autosave_id;
        }

        $this->apply_staged_meta((int) $autosave_id, $payload);

        return (int) $autosave_id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function apply_staged_meta(int $autosave_id, array $payload): void {
        if (!array_key_exists('post_meta', $payload) || !is_array($payload['post_meta'])) {
            return;
        }

        foreach ($payload['post_meta'] as $key => $value) {
            $meta_key = sanitize_key((string) $key);

            if ('' === $meta_key) {
                continue;
            }

            update_post_meta($autosave_id, $meta_key, $value);
        }
    }
}
