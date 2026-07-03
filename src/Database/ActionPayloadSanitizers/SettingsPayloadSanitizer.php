<?php

/**
 * Sanitizes settings action payload fields.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes settings maps in action payloads.
 */
final class SettingsPayloadSanitizer
{
    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array
    {
        if (array_key_exists('settings_changes', $payload) && is_array($payload['settings_changes'])) {
            $clean['settings_changes'] = $this->sanitize_settings_map($payload['settings_changes']);
        }

        if (array_key_exists('original_settings', $payload) && is_array($payload['original_settings'])) {
            $clean['original_settings'] = $this->sanitize_settings_map($payload['original_settings']);
        }

        return $clean;
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return array<string, mixed>
     */
    private function sanitize_settings_map(array $settings): array
    {
        $clean = [];

        foreach (array_keys($settings) as $key) {
            if (!is_string($key)) {
                continue;
            }

            $clean[$key] = $this->sanitize_setting_value($settings[$key]);
        }

        return $clean;
    }

    private function sanitize_setting_value(mixed $value): string|int|bool
    {
        if (is_bool($value) || is_int($value)) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }
}
