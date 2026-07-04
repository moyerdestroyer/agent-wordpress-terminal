<?php

/**
 * Creates and manages hidden draft posts used to preview new-post actions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * New-post actions have no existing post to autosave against, so AWPT inserts a
 * marked staging draft that can be previewed before apply and trashed on reject.
 */
final class NewPostStagingDraft
{
    public const META_KEY = '_awpt_staging_draft';

    /**
     * @param array<string, mixed> $payload
     * @return int|\WP_Error
     */
    public function create(array $payload): int|\WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return new \WP_Error(
                code: 'awpt_cannot_create_post',
                message: __('You do not have permission to create posts.', 'agent-wordpress-terminal'),
                data: ['status' => 403],
            );
        }

        $post_title = trim(sanitize_text_field((string) ($payload['post_title'] ?? '')));
        $post_content = trim((string) ($payload['post_content'] ?? ''));

        if ('' === $post_title || '' === $post_content) {
            return new \WP_Error(
                code: 'awpt_invalid_new_post',
                message: __('A post title and content are required to preview a new post.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $post_type = sanitize_key((string) ($payload['post_type'] ?? 'post'));

        if (!in_array($post_type, ['post', 'page'], true)) {
            $post_type = 'post';
        }

        $post_id = wp_insert_post([
            'post_title' => $post_title,
            'post_content' => wp_kses_post($post_content),
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, self::META_KEY, 1);
        $this->apply_featured_image($post_id, $payload);

        return (int) $post_id;
    }

    /**
     * @param array<string, mixed> $payload
     * @return true|\WP_Error
     */
    public function sync(int $post_id, array $payload): true|\WP_Error
    {
        if (!$this->is_staging_draft($post_id)) {
            return new \WP_Error(
                code: 'awpt_invalid_staging_post',
                message: __('The staged preview post could not be found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_Error(
                code: 'awpt_cannot_preview_post',
                message: __('You do not have permission to preview this post.', 'agent-wordpress-terminal'),
                data: ['status' => 403],
            );
        }

        $update = ['ID' => $post_id];

        if (array_key_exists('post_title', $payload)) {
            $update['post_title'] = sanitize_text_field((string) $payload['post_title']);
        }

        if (array_key_exists('post_content', $payload)) {
            $update['post_content'] = wp_kses_post((string) $payload['post_content']);
        }

        if (array_key_exists('post_type', $payload)) {
            $post_type = sanitize_key((string) $payload['post_type']);

            if (in_array($post_type, ['post', 'page'], true)) {
                $update['post_type'] = $post_type;
            }
        }

        if (count($update) > 1) {
            $updated = wp_update_post($update, wp_error: true);

            if (is_wp_error($updated)) {
                return $updated;
            }
        }

        $this->apply_featured_image($post_id, $payload);

        return true;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public function discard(array $payload): void
    {
        $post_id = (int) ($payload['post_id'] ?? 0);

        if ($post_id <= 0 || !$this->is_staging_draft($post_id)) {
            return;
        }

        wp_trash_post($post_id);
    }

    public function finalize(int $post_id): void
    {
        delete_post_meta($post_id, self::META_KEY);
    }

    public function is_staging_draft(int $post_id): bool
    {
        return (bool) get_post_meta($post_id, self::META_KEY, true);
    }

    /**
     * Set a featured image when needed. WordPress's set_post_thumbnail() returns false when
     * the attachment is already assigned, so success is verified by reading _thumbnail_id.
     */
    public function ensure_featured_image(int $post_id, int $attachment_id): bool
    {
        if ($attachment_id <= 0) {
            return true;
        }

        if ((int) get_post_thumbnail_id($post_id) === $attachment_id) {
            return true;
        }

        set_post_thumbnail($post_id, $attachment_id);

        return (int) get_post_thumbnail_id($post_id) === $attachment_id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function apply_featured_image(int $post_id, array $payload): void
    {
        $featured_image_id = (int) ($payload['featured_image_id'] ?? 0);

        if ($featured_image_id > 0) {
            $this->ensure_featured_image($post_id, $featured_image_id);
        }
    }
}
