<?php

/**
 * Content fields for staged action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\PostContentSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes post/block/meta fields on staged actions.
 */
final class ActionContentPayloadSanitizer {
    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array {
        $clean = $this->copy_text_fields($clean, $payload);
        $clean = $this->copy_html_fields($clean, $payload);
        $clean = $this->copy_preview_fields($clean, $payload);
        $clean = $this->copy_meta_fields($clean, $payload);

        return $this->copy_block_fields($clean, $payload);
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_text_fields(array $clean, array $payload): array {
        foreach (['post_title', 'post_type', 'post_status', 'original_post_title', 'original_post_status'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_text_field((string) $payload[$key]);
        }

        if (array_key_exists('affected', $payload)) {
            $clean['affected'] = sanitize_textarea_field((string) $payload['affected']);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_html_fields(array $clean, array $payload): array {
        foreach (['post_content', 'original_post_content'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = PostContentSanitizer::for_staged_update((string) $payload[$key]);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_preview_fields(array $clean, array $payload): array {
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
    private function copy_meta_fields(array $clean, array $payload): array {
        if (array_key_exists('post_meta', $payload) && is_array($payload['post_meta'])) {
            $clean['post_meta'] = $this->sanitize_meta_map($payload['post_meta']);
        }

        if (array_key_exists('original_post_meta', $payload) && is_array($payload['original_post_meta'])) {
            $clean['original_post_meta'] = $this->sanitize_meta_map($payload['original_post_meta']);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_block_fields(array $clean, array $payload): array {
        $clean = $this->copy_block_identity_fields($clean, $payload);

        if (array_key_exists('attrs', $payload) && is_array($payload['attrs'])) {
            $clean['attrs'] = $this->sanitize_attrs_map($payload['attrs']);
        }

        if (array_key_exists('block', $payload) && is_array($payload['block'])) {
            $clean['block'] = $this->sanitize_block_definition($payload['block']);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_block_identity_fields(array $clean, array $payload): array {
        foreach (['block_path', 'block_name', 'expected_fingerprint', 'inserted_path'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_text_field((string) $payload[$key]);
        }

        if (array_key_exists('position', $payload)) {
            $clean['position'] = sanitize_key((string) $payload['position']);
        }

        return $clean;
    }

    /**
     * @param array<array-key, mixed> $block
     * @return array<string, mixed>
     */

    private function sanitize_block_definition(array $block): array {
        $name = sanitize_text_field((string) ($block['blockName'] ?? ''));
        $attrs = is_array($block['attrs'] ?? null) ? $this->sanitize_attrs_map($block['attrs']) : [];
        $inner_html = is_string($block['innerHTML'] ?? null) ? wp_kses_post($block['innerHTML']) : '';

        return [
            'blockName' => $name,
            'attrs' => $attrs,
            'innerHTML' => $inner_html,
            'innerBlocks' => [],
            'innerContent' => [$inner_html],
        ];
    }

    /**
     * @param array<array-key, mixed> $meta
     * @return array<string, string|int|float|bool>
     */

    private function sanitize_meta_map(array $meta): array {
        $clean = [];

        foreach ($meta as $key => $value) {
            $meta_key = sanitize_key((string) $key);

            if ('' === $meta_key) {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$meta_key] = $value;
                continue;
            }

            $clean[$meta_key] = sanitize_text_field((string) $value);
        }

        return $clean;
    }

    private function sanitize_attr_value(mixed $value): mixed {
        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $clean[$key] = $this->sanitize_attr_value($item);
            }

            return $clean;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param array<array-key, mixed> $attrs
     * @return array<string, mixed>
     */
    public function sanitize_attrs_map(array $attrs): array {
        $clean = [];

        foreach ($attrs as $key => $value) {
            if (!is_string($key) || '' === $key) {
                continue;
            }

            $clean[$key] = $this->sanitize_attr_value($value);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
}
