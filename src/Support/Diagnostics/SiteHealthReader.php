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

        $scope = (string) ($options['scope'] ?? 'summary');
        $run_async = (bool) ($options['run_async'] ?? 'full' === $scope);
        $filter_tests = is_array($options['tests'] ?? null) ? $options['tests'] : [];
        $environment = new SiteHealthEnvironmentSnapshot();

        if ('environment_only' === $scope) {
            return [
                'overall' => ['good' => 0, 'recommended' => 0, 'critical' => 0],
                'environment' => $environment->capture(),
                'tests' => [],
                'cached_status' => $environment->cached_issue_counts(),
            ];
        }

        $definitions = \WP_Site_Health::get_instance()->get_tests();
        $tests = new SiteHealthDirectRunner()->run($definitions['direct'] ?? [], $filter_tests);
        $overall = $this->count_statuses($tests);

        if ($run_async && ('full' === $scope || [] !== $filter_tests)) {
            $async_tests = new SiteHealthAsyncRunner()->run($definitions['async'] ?? [], $filter_tests);
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
            'environment' => $environment->capture(),
            'tests' => $tests,
            'cached_status' => $environment->cached_issue_counts(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $tests
     * @return list<array<string, mixed>>
     */
    public function correlate_tests(?string $error_type, array $tests): array {
        return new SiteHealthCorrelator()->correlate($error_type, $tests);
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
