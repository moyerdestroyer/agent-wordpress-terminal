<?php

/**
 * Sanitizes staged action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Database\ActionPayloadSanitizers\ContentPayloadSanitizer;
use AWPT\Database\ActionPayloadSanitizers\SettingsPayloadSanitizer;
use AWPT\Database\ActionPayloadSanitizers\ThemePayloadSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Normalizes action payloads before storage.
 */
final class ActionPayloadSanitizer
{
    private ContentPayloadSanitizer $content;
    private SettingsPayloadSanitizer $settings;
    private ThemePayloadSanitizer $theme;

    public function __construct(
        ?ContentPayloadSanitizer $content = null,
        ?SettingsPayloadSanitizer $settings = null,
        ?ThemePayloadSanitizer $theme = null,
    ) {
        $this->content = $content ?? new ContentPayloadSanitizer();
        $this->settings = $settings ?? new SettingsPayloadSanitizer();
        $this->theme = $theme ?? new ThemePayloadSanitizer();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $clean = [
            'operation' => sanitize_key((string) ($payload['operation'] ?? 'content_update')),
            'post_id' => $this->to_absint($payload['post_id'] ?? 0),
        ];

        $clean = $this->content->sanitize($clean, $payload);
        $clean = $this->settings->sanitize($clean, $payload);

        return $this->theme->sanitize($clean, $payload);
    }

    private function to_absint(mixed $value): int
    {
        return absint(is_scalar($value) ? $value : 0);
    }
}
