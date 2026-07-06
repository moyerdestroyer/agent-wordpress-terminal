<?php

/**
 * Sanitizes content action payload fields.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

use AWPT\Support\PostContentSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes post-related action payload fields.
 */
final class ContentPayloadSanitizer {
    private PostMetaPayloadSanitizer $post_meta;
    private BlockAttrsPayloadSanitizer $block_attrs;

    public function __construct(
        ?PostMetaPayloadSanitizer $post_meta = null,
        ?BlockAttrsPayloadSanitizer $block_attrs = null,
    ) {
        $this->post_meta = $post_meta ?? new PostMetaPayloadSanitizer();
        $this->block_attrs = $block_attrs ?? new BlockAttrsPayloadSanitizer();
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array {
        $clean = $this->sanitize_text_fields($clean, $payload);
        $clean = $this->sanitize_html_fields($clean, $payload);
        $clean = $this->sanitize_preview_fields($clean, $payload);

        if (array_key_exists('affected', $payload)) {
            $clean['affected'] = sanitize_textarea_field((string) $payload['affected']);
        }

        if (array_key_exists('post_meta', $payload) && is_array($payload['post_meta'])) {
            $clean['post_meta'] = $this->post_meta->sanitize_map($payload['post_meta']);
        }

        if (array_key_exists('original_post_meta', $payload) && is_array($payload['original_post_meta'])) {
            $clean['original_post_meta'] = $this->post_meta->sanitize_map($payload['original_post_meta']);
        }

        if (array_key_exists('block_path', $payload)) {
            $clean['block_path'] = sanitize_text_field((string) $payload['block_path']);
        }

        if (array_key_exists('block_name', $payload)) {
            $clean['block_name'] = sanitize_text_field((string) $payload['block_name']);
        }

        if (array_key_exists('expected_fingerprint', $payload)) {
            $clean['expected_fingerprint'] = sanitize_text_field((string) $payload['expected_fingerprint']);
        }

        if (array_key_exists('attrs', $payload) && is_array($payload['attrs'])) {
            $clean['attrs'] = $this->block_attrs->sanitize_map($payload['attrs']);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize_preview_fields(array $clean, array $payload): array {
        if (array_key_exists('preview_url', $payload)) {
            $clean['preview_url'] = esc_url_raw((string) $payload['preview_url']);
        }

        if (array_key_exists('preview_autosave_id', $payload)) {
            $clean['preview_autosave_id'] = absint(
                is_scalar($payload['preview_autosave_id']) ? $payload['preview_autosave_id'] : 0,
            );
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize_text_fields(array $clean, array $payload): array {
        foreach (['post_title', 'post_type', 'post_status', 'original_post_title', 'original_post_status'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_text_field((string) $payload[$key]);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize_html_fields(array $clean, array $payload): array {
        foreach (['post_content', 'original_post_content'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = PostContentSanitizer::for_staged_update((string) $payload[$key]);
        }

        return $clean;
    }
}
