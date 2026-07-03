<?php

/**
 * Sanitizes content action payload fields.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes post-related action payload fields.
 */
final class ContentPayloadSanitizer
{
    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array
    {
        $clean = $this->sanitize_text_fields($clean, $payload);
        $clean = $this->sanitize_html_fields($clean, $payload);

        if (array_key_exists('preview_url', $payload)) {
            $clean['preview_url'] = esc_url_raw((string) $payload['preview_url']);
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
    private function sanitize_text_fields(array $clean, array $payload): array
    {
        foreach (['post_title', 'post_type', 'post_status', 'original_post_title'] as $key) {
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
    private function sanitize_html_fields(array $clean, array $payload): array
    {
        foreach (['post_content', 'original_post_content'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = wp_kses_post((string) $payload[$key]);
        }

        return $clean;
    }
}
