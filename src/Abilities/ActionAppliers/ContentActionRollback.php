<?php

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/** Restores a review-safe content action when its applied state is still current. */
final class ContentActionRollback {
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function rollback(array $payload): array|\WP_Error {
        $operation = (string) ($payload['operation'] ?? '');
        $post_id = (int) ($payload['post_id'] ?? 0);
        $snapshot = is_array($payload['review_undo_snapshot'] ?? null) ? $payload['review_undo_snapshot'] : [];

        if (!ActionOperations::is_review_safe_content($operation) || $post_id <= 0 || [] === $snapshot) {
            return new \WP_Error(
                'awpt_rollback_unsupported',
                __('This action cannot be undone from Review.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_Error(
                'awpt_cannot_edit_post',
                __('You do not have permission to restore this page.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('The page no longer exists.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        if (!$this->matches_applied_state($post, $payload)) {
            return new \WP_Error(
                'awpt_rollback_conflict',
                __(
                    'This page changed after the review action was applied. Restore it manually or ask the agent for a new change.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409],
            );
        }

        $updated = wp_update_post([
            'ID' => $post_id,
            'post_title' => (string) ($snapshot['post_title'] ?? $post->post_title),
            'post_content' => (string) ($snapshot['post_content'] ?? $post->post_content),
            'post_status' => (string) ($snapshot['post_status'] ?? $post->post_status),
        ], wp_error: true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        $meta = $snapshot['meta'] ?? [];
        foreach (is_array($meta) ? $meta : [] as $key => $item) {
            if (!is_string($key) || !is_array($item)) {
                continue;
            }

            if (($item['exists'] ?? false) === true) {
                update_post_meta($post_id, $key, $item['value'] ?? '');
            } else {
                delete_post_meta($post_id, $key);
            }
        }

        return ['post_id' => $post_id];
    }

    /** @param array<string, mixed> $payload */
    private function matches_applied_state(\WP_Post $post, array $payload): bool {
        $fingerprint = (string) ($payload['review_applied_fingerprint'] ?? '');

        return $fingerprint !== '' && hash_equals($fingerprint, $this->fingerprint($post, $payload));
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(\WP_Post $post, array $payload): string {
        $meta = [];
        $post_meta = $payload['post_meta'] ?? [];
        foreach (array_keys(is_array($post_meta) ? $post_meta : []) as $key) {
            $key = sanitize_key((string) $key);
            if ($key !== '') {
                $meta[$key] = get_post_meta($post->ID, $key, true);
            }
        }

        $encoded = wp_json_encode([
            'title' => $post->post_title,
            'content' => $post->post_content,
            'status' => $post->post_status,
            'meta' => $meta,
        ]);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }
}
