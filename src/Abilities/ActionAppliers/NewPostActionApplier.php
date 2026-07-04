<?php

/**
 * Applies staged new-post creation.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

use AWPT\Support\NewPostStagingDraft;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates a brand new post/page from a staged action. Always inserted as a draft,
 * regardless of what was proposed — publishing remains a separate, deliberate admin
 * action taken outside of AWPT.
 */
final class NewPostActionApplier
{
    private NewPostStagingDraft $staging_drafts;

    public function __construct(?NewPostStagingDraft $staging_drafts = null)
    {
        $this->staging_drafts = $staging_drafts ?? new NewPostStagingDraft();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error
    {
        if (!current_user_can('edit_posts')) {
            return new \WP_Error(
                code: 'awpt_cannot_create_post',
                message: __('You do not have permission to create posts.', 'agent-wordpress-terminal'),
                data: ['status' => 403],
            );
        }

        $post_title = trim((string) ($payload['post_title'] ?? ''));
        $post_content = trim((string) ($payload['post_content'] ?? ''));

        if ('' === $post_title || '' === $post_content) {
            return new \WP_Error(
                code: 'awpt_empty_action',
                message: __('The staged post has no title or content to create.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $post_type = sanitize_key((string) ($payload['post_type'] ?? 'post'));

        if (!in_array($post_type, ['post', 'page'], true)) {
            $post_type = 'post';
        }

        $staging_post_id = (int) ($payload['post_id'] ?? 0);
        $is_staging_draft = (bool) ($payload['staging_draft'] ?? false);

        if ($staging_post_id > 0 && ($is_staging_draft || $this->staging_drafts->is_staging_draft($staging_post_id))) {
            $post_id = wp_update_post([
                'ID' => $staging_post_id,
                'post_title' => sanitize_text_field($post_title),
                'post_content' => wp_kses_post($post_content),
                'post_type' => $post_type,
                'post_status' => 'draft',
            ], true);

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $this->staging_drafts->finalize($staging_post_id);
            $post_id = $staging_post_id;
        } else {
            $post_id = wp_insert_post([
                'post_title' => sanitize_text_field($post_title),
                'post_content' => wp_kses_post($post_content),
                'post_type' => $post_type,
                'post_status' => 'draft',
                'post_author' => get_current_user_id(),
            ], true);

            if (is_wp_error($post_id)) {
                return $post_id;
            }
        }

        $featured_image_id = (int) ($payload['featured_image_id'] ?? 0);

        if ($featured_image_id > 0 && !$this->staging_drafts->ensure_featured_image($post_id, $featured_image_id)) {
            return new \WP_Error(
                code: 'awpt_featured_image_failed',
                message: __(
                    'The post was created but its featured image could not be set.',
                    'agent-wordpress-terminal',
                ),
                data: ['status' => 500],
            );
        }

        $result = [
            'post_id' => $post_id,
            'edit_url' => (string) get_edit_post_link($post_id, 'raw'),
        ];

        if ($featured_image_id > 0) {
            $result['featured_image_id'] = $featured_image_id;
        }

        return $result;
    }
}
