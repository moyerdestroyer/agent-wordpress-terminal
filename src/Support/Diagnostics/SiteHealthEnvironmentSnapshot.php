<?php

/**
 * Site Health environment snapshot helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds environment and cached status slices for Site Health reads.
 */
final class SiteHealthEnvironmentSnapshot {
    /**
     * @return array<string, mixed>
     */
    public function capture(): array {
        global $wpdb;

        $database_extension = 'unknown';

        if ($wpdb->db_version()) {
            $database_extension = $wpdb->use_mysqli ? 'mysqli' : 'mysql';
        }

        return [
            'php_version' => PHP_VERSION,
            'php_memory_limit' => (string) ini_get('memory_limit'),
            'php_max_execution_time' => (string) ini_get('max_execution_time'),
            'wp_version' => get_bloginfo('version'),
            'database_extension' => $database_extension,
            'persistent_object_cache' => wp_using_ext_object_cache(),
            'wp_debug' => defined('WP_DEBUG') && \WP_DEBUG,
            'wp_debug_log' => defined('WP_DEBUG_LOG') && \WP_DEBUG_LOG,
        ];
    }

    /**
     * @return array{issues: array{good: int, recommended: int, critical: int}}|array{}
     */
    public function cached_issue_counts(): array {
        $cached = get_transient('health-check-site-status-result');

        if (!is_array($cached)) {
            return [];
        }

        return [
            'issues' => [
                'good' => (int) ($cached['good'] ?? 0),
                'recommended' => (int) ($cached['recommended'] ?? 0),
                'critical' => (int) ($cached['critical'] ?? 0),
            ],
        ];
    }
}
