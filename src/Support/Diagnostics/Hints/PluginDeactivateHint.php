<?php

/**
 * Plugin deactivation remediation hint.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics\Hints;

use AWPT\Support\Diagnostics\PluginInventory;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * deactivate_plugin hint rule (gated behind unambiguous attribution).
 */
final class PluginDeactivateHint {
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public static function build(array $context, PluginInventory $inventory): ?array {
        $primary = self::primary_suspect($context);

        if (null === $primary || 'plugin' !== ($primary['kind'] ?? '') || 'high' !== ($primary['confidence'] ?? '')) {
            return null;
        }

        $error_type = $context['error_type'] ?? null;
        $error_type = is_string($error_type) ? $error_type : null;

        if (!in_array($error_type, ['php_fatal', 'php_exception'], true)) {
            return null;
        }

        $slug = (string) ($primary['slug'] ?? '');
        $evidence_lines = is_array($context['evidence'] ?? null) ? $context['evidence'] : [];
        $evidence = array_values(array_map(static fn(mixed $line): string => (string) $line, $evidence_lines));

        if (!self::evidence_mentions_plugin($evidence, $slug)) {
            return null;
        }

        $file = $inventory->file_for_slug($slug);

        if (null === $file || str_contains($file, 'agent-wordpress-terminal/')) {
            return null;
        }

        return [
            'type' => 'deactivate_plugin',
            'plugin_file' => $file,
            'plugin_slug' => $slug,
            'confidence' => 'unambiguous',
            'reason' => sprintf(
                /* translators: %s: plugin slug */
                __(
                    'Fatal PHP error trace unambiguously points to plugin %s; stage deactivation only if other fixes are ruled out.',
                    'agent-wordpress-terminal',
                ),
                $slug,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private static function primary_suspect(array $context): ?array {
        $suspects = is_array($context['suspects'] ?? null) ? $context['suspects'] : [];
        $primary = $suspects[0] ?? null;

        if (!is_array($primary)) {
            return null;
        }

        /** @var array<string, mixed> $primary */
        return $primary;
    }

    /**
     * @param list<string> $evidence
     */
    private static function evidence_mentions_plugin(array $evidence, string $slug): bool {
        if ('' === $slug) {
            return false;
        }

        $needle = 'wp-content/plugins/' . $slug . '/';

        foreach ($evidence as $line) {
            if (str_contains(strtolower($line), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
