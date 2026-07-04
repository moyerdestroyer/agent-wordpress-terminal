<?php

/**
 * Builds frontend preview URLs for staged (not yet applied) post changes.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Writes a per-user autosave revision so WordPress's native preview can render staged edits.
 *
 * For brand-new posts, delegates to a hidden staging draft that can be previewed before apply.
 */
final class StagedPostPreview
{
    private NewPostStagingDraft $staging_drafts;
    private ContentUpdatePreviewAutosave $content_autosaves;

    public function __construct(
        ?NewPostStagingDraft $staging_drafts = null,
        ?ContentUpdatePreviewAutosave $content_autosaves = null,
    ) {
        $this->staging_drafts = $staging_drafts ?? new NewPostStagingDraft();
        $this->content_autosaves = $content_autosaves ?? new ContentUpdatePreviewAutosave();
    }

    /**
     * @param array<string, mixed> $payload Staged action payload.
     * @return array<string, mixed>|\WP_Error
     */
    public function preview_from_payload(array $payload): array|\WP_Error
    {
        if (ActionOperations::NEW_POST === (string) ($payload['operation'] ?? ActionOperations::CONTENT_UPDATE)) {
            return $this->preview_new_post($payload);
        }

        return $this->preview_content_update($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function prepare_new_post_payload(array $payload): array|\WP_Error
    {
        $post_id = (int) ($payload['post_id'] ?? 0);

        if ($post_id <= 0) {
            $created = $this->staging_drafts->create($payload);

            if (is_wp_error($created)) {
                return $created;
            }

            $post_id = $created;
            $payload['post_id'] = $post_id;
            $payload['staging_draft'] = true;
        } else {
            $synced = $this->staging_drafts->sync($post_id, $payload);

            if (is_wp_error($synced)) {
                return $synced;
            }
        }

        $preview = $this->build_preview_response($post_id, $payload);

        if (is_wp_error($preview)) {
            return $preview;
        }

        $payload['preview_url'] = $preview['preview_url'];

        return $payload;
    }

    /**
     * Remove preview artifacts created for a staged action (staging draft or autosave).
     *
     * @param array<array-key, mixed> $payload
     */
    public function discard_preview_resources(array $payload): void
    {
        $operation = (string) ($payload['operation'] ?? '');

        if (ActionOperations::NEW_POST === $operation) {
            $this->staging_drafts->discard($payload);

            return;
        }

        if (ActionOperations::CONTENT_UPDATE === $operation) {
            $this->content_autosaves->discard($payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function discard_staging_draft(array $payload): void
    {
        $this->discard_preview_resources($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    private function preview_new_post(array $payload): array|\WP_Error
    {
        $prepared = $this->prepare_new_post_payload($payload);

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        return $this->build_preview_response((int) $prepared['post_id'], $prepared);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    private function preview_content_update(array $payload): array|\WP_Error
    {
        $post_id = (int) ($payload['post_id'] ?? 0);

        if ($post_id <= 0) {
            return new \WP_Error(
                code: 'awpt_invalid_preview_post',
                message: __('Preview requires a valid post ID.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_Error(
                code: 'awpt_cannot_preview_post',
                message: __('You do not have permission to preview this post.', 'agent-wordpress-terminal'),
                data: ['status' => 403],
            );
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(
                code: 'awpt_post_not_found',
                message: __('Post not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $autosave_id = $this->content_autosaves->create($this->build_autosave_data($post, $payload), $payload);

        if (is_wp_error($autosave_id)) {
            return $autosave_id;
        }

        $preview = $this->build_preview_response($post_id, $payload);

        if (is_wp_error($preview)) {
            wp_delete_post((int) $autosave_id, true);

            return $preview;
        }

        $preview['autosave_id'] = $autosave_id;

        return $preview;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    private function build_preview_response(int $post_id, array $payload): array|\WP_Error
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(
                code: 'awpt_post_not_found',
                message: __('Post not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $preview_url = get_preview_post_link($post);

        if (!is_string($preview_url) || '' === $preview_url) {
            $preview_url = (string) get_permalink($post);
        }

        if ('' === $preview_url) {
            return new \WP_Error(
                code: 'awpt_preview_url_failed',
                message: __('Could not build a preview URL for this post.', 'agent-wordpress-terminal'),
                data: ['status' => 500],
            );
        }

        $title = array_key_exists('post_title', $payload)
            ? sanitize_text_field((string) $payload['post_title'])
            : get_the_title($post);

        return [
            'id' => $post_id,
            'title' => $title,
            'status' => array_key_exists('post_status', $payload)
                ? sanitize_key((string) $payload['post_status'])
                : $post->post_status,
            'preview_url' => $preview_url,
            'iframe' => [
                'src' => $preview_url,
                'title' => $title,
                'height' => 640,
            ],
        ];
    }

    /**
     * @param \WP_Post $post
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function build_autosave_data(\WP_Post $post, array $payload): array
    {
        return [
            'ID' => 0,
            'post_ID' => $post->ID,
            'post_title' => array_key_exists('post_title', $payload)
                ? sanitize_text_field((string) $payload['post_title'])
                : $post->post_title,
            'post_content' => array_key_exists('post_content', $payload)
                ? wp_kses_post((string) $payload['post_content'])
                : $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => array_key_exists('post_status', $payload)
                ? sanitize_key((string) $payload['post_status'])
                : $post->post_status,
            'post_type' => $post->post_type,
        ];
    }
}
