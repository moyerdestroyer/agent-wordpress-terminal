<?php

/**
 * Sanitizes theme action payload fields.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes theme-related action payload fields.
 */
final class ThemePayloadSanitizer
{
    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array
    {
        $clean = $this->sanitize_stylesheet_fields($clean, $payload);

        return $this->sanitize_theme_name_fields($clean, $payload);
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize_stylesheet_fields(array $clean, array $payload): array
    {
        foreach (['stylesheet', 'current_stylesheet'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_key((string) $payload[$key]);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize_theme_name_fields(array $clean, array $payload): array
    {
        foreach (['theme_name', 'current_theme'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_text_field((string) $payload[$key]);
        }

        return $clean;
    }
}
