<?php

/**
 * Sanitizes plugin action payload fields.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes plugin-related action payload fields.
 */
final class PluginPayloadSanitizer {
    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array {
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
}
