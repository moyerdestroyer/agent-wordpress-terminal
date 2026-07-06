<?php

/**
 * Sanitizes settings action payload fields.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

use AWPT\Support\SiteSettingsWhitelist;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes settings maps in action payloads.
 */
final class SettingsPayloadSanitizer {
    private SiteSettingsWhitelist $whitelist;

    public function __construct(?SiteSettingsWhitelist $whitelist = null) {
        $this->whitelist = $whitelist ?? new SiteSettingsWhitelist();
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array {
        if (array_key_exists('settings_changes', $payload) && is_array($payload['settings_changes'])) {
            $clean['settings_changes'] = $this->whitelist->sanitize_map($payload['settings_changes']);
        }

        if (array_key_exists('original_settings', $payload) && is_array($payload['original_settings'])) {
            $clean['original_settings'] = $this->whitelist->sanitize_map($payload['original_settings']);
        }

        return $clean;
    }
}
