<?php

/**
 * Normalizes Site Health test result arrays.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts raw Site Health test payloads into agent-friendly rows.
 */
final class SiteHealthResultNormalizer {
    /**
     * @param array<string, mixed> $result
     * @return array{slug: string, label: string, status: string, description: string, actions: string}
     */
    public function normalize(string $slug, array $result): array {
        $status = (string) ($result['status'] ?? 'good');

        if (!in_array($status, ['good', 'recommended', 'critical'], true)) {
            $status = 'good';
        }

        return [
            'slug' => $slug,
            'label' => (string) ($result['label'] ?? $slug),
            'status' => $status,
            'description' => mb_substr(wp_strip_all_tags((string) ($result['description'] ?? '')), 0, 500),
            'actions' => mb_substr(wp_strip_all_tags((string) ($result['actions'] ?? '')), 0, 300),
        ];
    }
}
