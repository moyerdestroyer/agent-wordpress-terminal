<?php

/**
 * Sanitizes staged action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Database\ActionPayloadSanitizers\ContentPayloadSanitizer;
use AWPT\Database\ActionPayloadSanitizers\PluginPayloadSanitizer;
use AWPT\Database\ActionPayloadSanitizers\SettingsPayloadSanitizer;
use AWPT\Database\ActionPayloadSanitizers\ThemePayloadSanitizer;
use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Normalizes action payloads before storage.
 */
final class ActionPayloadSanitizer {
    private ContentPayloadSanitizer $content;
    private SettingsPayloadSanitizer $settings;
    private ThemePayloadSanitizer $theme;
    private PluginPayloadSanitizer $plugins;

    public function __construct(
        ?ContentPayloadSanitizer $content = null,
        ?SettingsPayloadSanitizer $settings = null,
        ?ThemePayloadSanitizer $theme = null,
        ?PluginPayloadSanitizer $plugins = null,
    ) {
        $this->content = $content ?? new ContentPayloadSanitizer();
        $this->settings = $settings ?? new SettingsPayloadSanitizer();
        $this->theme = $theme ?? new ThemePayloadSanitizer();
        $this->plugins = $plugins ?? new PluginPayloadSanitizer();
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

        $clean = $this->content->sanitize($clean, $payload);

        if (array_key_exists('featured_image_id', $payload)) {
            $clean['featured_image_id'] = $this->to_absint($payload['featured_image_id']);
        }

        if (array_key_exists('staging_draft', $payload)) {
            $clean['staging_draft'] = filter_var($payload['staging_draft'], FILTER_VALIDATE_BOOLEAN);
        }

        $clean = $this->settings->sanitize($clean, $payload);
        $clean = $this->theme->sanitize($clean, $payload);

        return $this->plugins->sanitize($clean, $payload);
    }

    private function to_absint(mixed $value): int {
        return absint(is_scalar($value) ? $value : 0);
    }
}
