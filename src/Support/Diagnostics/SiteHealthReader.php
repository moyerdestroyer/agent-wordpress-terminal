<?php

/**
 * WordPress Site Health test runner for agent diagnostics.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs Core Site Health tests and returns normalized results.
 */
final class SiteHealthReader {
    /**
     * @return array<string, mixed>
     */
    public function summary(): array {
        return $this->read([
            'scope' => 'summary',
            'run_async' => false,
            'tests' => [],
        ]);
    }

    /**
     * @param array{scope?: string, run_async?: bool, tests?: list<string>} $options
     * @return array<string, mixed>|\WP_Error
     */
    public function read(array $options = []): array|\WP_Error {
        if (!$this->ensure_site_health_loaded()) {
            return new \WP_Error('awpt_site_health_unavailable', __(
                'WordPress Site Health is not available.',
                'agent-wordpress-terminal',
            ));
        }

        $scope = $options['scope'] ?? 'summary';
        $run_async = $options['run_async'] ?? 'full' === $scope;
        $filter_tests = is_array($options['tests'] ?? null) ? $options['tests'] : [];

        if ('environment_only' === $scope) {
            return [
                'overall' => ['good' => 0, 'recommended' => 0, 'critical' => 0],
                'environment' => $this->capture_environment(),
                'tests' => [],
                'cached_status' => $this->cached_issue_counts(),
            ];
        }

        $definitions = \WP_Site_Health::get_instance()->get_tests();
        /** @var array<string, array<string, mixed>> $direct */
        $direct = is_array($definitions['direct'] ?? null) ? $definitions['direct'] : [];
        /** @var array<string, array<string, mixed>> $async */
        $async = is_array($definitions['async'] ?? null) ? $definitions['async'] : [];
        $tests = $this->run_direct_tests($direct, $filter_tests);
        $overall = $this->count_statuses($tests);

        if ($run_async && ('full' === $scope || [] !== $filter_tests)) {
            $async_tests = new SiteHealthAsyncRunner()->run($async, $filter_tests);
            $tests = array_merge($tests, $async_tests);
            $async_counts = $this->count_statuses($async_tests);
            $overall['good'] += $async_counts['good'];
            $overall['recommended'] += $async_counts['recommended'];
            $overall['critical'] += $async_counts['critical'];
        }

        if ('summary' === $scope) {
            $tests = array_values(array_filter($tests, static fn(array $test): bool => in_array(
                $test['status'],
                ['recommended', 'critical'],
                true,
            )));
        }

        return [
            'overall' => $overall,
            'environment' => $this->capture_environment(),
            'tests' => $tests,
            'cached_status' => $this->cached_issue_counts(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $tests
     * @return list<array<string, mixed>>
     */
    public function correlate_tests(?string $error_type, array $tests): array {
        return $this->correlate($error_type, $tests);
    }

    /**
     * @param list<array<string, mixed>> $tests
     * @return list<array<string, mixed>>
     */
    public function correlate(?string $error_type, array $tests): array {
        if (null === $error_type) {
            return $this->non_good_tests($tests);
        }

        $keywords = match ($error_type) {
            'php_fatal' => ['memory', 'php', 'sql', 'database'],
            'js_error', 'js_unhandled_rejection' => ['loopback', 'https', 'rest', 'page_cache'],
            default => ['loopback', 'rest', 'php'],
        };

        $relevant = [];

        foreach ($tests as $test) {
            $haystack = strtolower((string) ($test['slug'] ?? '') . ' ' . ($test['label'] ?? ''));

            foreach ($keywords as $keyword) {
                if (!str_contains($haystack, $keyword)) {
                    continue;
                }

                $relevant[] = $test;
                break;
            }
        }

        if ([] === $relevant) {
            return $this->non_good_tests($tests);
        }

        return $relevant;
    }

    /**
     * @param array<string, array<string, mixed>> $direct_definitions
     * @param list<string>                        $filter_tests
     * @return list<array{slug: string, label: string, status: string, description: string, actions: string}>
     */
    private function run_direct_tests(array $direct_definitions, array $filter_tests): array {
        $tests = [];

        foreach ($direct_definitions as $slug => $definition) {
            if ([] !== $filter_tests && !in_array($slug, $filter_tests, true)) {
                continue;
            }

            if ('https_status' === $slug && $this->is_development_environment()) {
                continue;
            }

            $callback = $definition['test'] ?? null;

            if (!is_callable($callback)) {
                continue;
            }

            $result = $callback();

            if (!is_array($result)) {
                continue;
            }

            /** @var array<string, mixed> $result */
            $tests[] = $this->normalize_result($slug, $result);
        }

        return $tests;
    }

    private function normalize_result(string $slug, array $result): array {
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

    /**
     * @return array<string, mixed>
     */
    private function capture_environment(): array {
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
    private function cached_issue_counts(): array {
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

    /**
     * @param list<array<string, mixed>> $tests
     * @return array{good: int, recommended: int, critical: int}
     */
    private function count_statuses(array $tests): array {
        $overall = ['good' => 0, 'recommended' => 0, 'critical' => 0];

        foreach ($tests as $test) {
            ++$overall[(string) ($test['status'] ?? 'good')];
        }

        return $overall;
    }

    /**
     * @param list<array<string, mixed>> $tests
     * @return list<array<string, mixed>>
     */
    private function non_good_tests(array $tests): array {
        return array_values(array_filter($tests, static fn(array $test): bool => in_array(
            $test['status'] ?? '',
            ['recommended', 'critical'],
            true,
        )));
    }

    private function is_development_environment(): bool {
        if (function_exists('wp_get_environment_type')) {
            $type = wp_get_environment_type();

            return in_array($type, ['local', 'development'], true);
        }

        return defined('WP_DEBUG') && \WP_DEBUG;
    }

    private function ensure_site_health_loaded(): bool {
        if (!class_exists('WP_Site_Health')) {
            $path = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

            if (!file_exists($path)) {
                return false;
            }

            require_once $path;
        }

        return class_exists('WP_Site_Health');
    }
}
