<?php

/**
 * Sanitizes staged action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\ActionOperations;
use AWPT\Support\SiteSettingsWhitelist;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Normalizes action payloads before storage.
 */
final class ActionPayloadSanitizer {
    private SiteSettingsWhitelist $whitelist;

    public function __construct(?SiteSettingsWhitelist $whitelist = null) {
        $this->whitelist = $whitelist ?? new SiteSettingsWhitelist();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array {
        $operation = sanitize_key((string) ($payload['operation'] ?? ''));

        if (!ActionOperations::is_valid($operation)) {
            return ['operation' => ''];
        }

        $clean = [
            'operation' => $operation,
            'post_id' => $this->to_absint($payload['post_id'] ?? 0),
        ];

        $clean = new ActionContentPayloadSanitizer()->sanitize($clean, $payload);

        if (array_key_exists('featured_image_id', $payload)) {
            $clean['featured_image_id'] = $this->to_absint($payload['featured_image_id']);
        }

        if (array_key_exists('staging_draft', $payload)) {
            $clean['staging_draft'] = filter_var($payload['staging_draft'], FILTER_VALIDATE_BOOLEAN);
        }

        $clean = $this->sanitize_settings($clean, $payload);
        $clean = $this->sanitize_theme($clean, $payload);

        return $this->sanitize_plugin($clean, $payload);
    }

    /**
     * Sanitize a block attributes map for staged storage.
     *
     * @param array<array-key, mixed> $attrs
     * @return array<string, mixed>
     */
    public function sanitize_attrs_map(array $attrs): array {
        return new ActionContentPayloadSanitizer()->sanitize_attrs_map($attrs);
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */

    private function sanitize_settings(array $clean, array $payload): array {
        if (array_key_exists('settings_changes', $payload) && is_array($payload['settings_changes'])) {
            $clean['settings_changes'] = $this->whitelist->sanitize_map($payload['settings_changes']);
        }

        if (array_key_exists('original_settings', $payload) && is_array($payload['original_settings'])) {
            $clean['original_settings'] = $this->whitelist->sanitize_map($payload['original_settings']);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize_theme(array $clean, array $payload): array {
        foreach (['stylesheet', 'current_stylesheet'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_key((string) $payload[$key]);
        }

        foreach (['theme_name', 'current_theme'] as $key) {
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
    private function sanitize_plugin(array $clean, array $payload): array {
        if (ActionOperations::PLUGIN_DEACTIVATE !== ($clean['operation'] ?? '')) {
            return $clean;
        }

        $clean['plugin_file'] = sanitize_text_field((string) ($payload['plugin_file'] ?? ''));
        $clean['plugin_slug'] = sanitize_key((string) ($payload['plugin_slug'] ?? ''));
        $clean['plugin_name'] = sanitize_text_field((string) ($payload['plugin_name'] ?? ''));
        $clean['was_active'] = filter_var($payload['was_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $clean['affected'] = sanitize_text_field((string) ($payload['affected'] ?? ''));

        return $clean;
    }

    private function to_absint(mixed $value): int {
        return absint(is_scalar($value) ? $value : 0);
    }
}
