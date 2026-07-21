<?php

/**
 * REST-backed async Site Health tests.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Executes async Site Health tests with a time budget.
 */
final class SiteHealthAsyncRunner {
    private const ASYNC_BUDGET_SECONDS = 10;

    /**
     * @var list<string>
     */
    private const ASYNC_REST_SLUGS = [
        'background_updates',
        'loopback_requests',
        'https_status',
        'dotorg_communication',
        'authorization_header',
        'page_cache',
    ];

    public function run(array $async_definitions, array $filter_tests): array {
        $tests = [];
        $deadline = microtime(true) + self::ASYNC_BUDGET_SECONDS;

        foreach ($async_definitions as $slug => $definition) {
            if (microtime(true) >= $deadline) {
                break;
            }

            if ([] !== $filter_tests && !in_array($slug, $filter_tests, true)) {
                continue;
            }

            if ('https_status' === $slug && $this->is_development_environment()) {
                continue;
            }

            if ('directory_sizes' === $slug) {
                continue;
            }

            if (!is_string($slug) || !is_array($definition)) {
                continue;
            }
            /** @var array<string, mixed> $definition */
            $rest_slug = $this->async_rest_slug($slug, $definition);

            if (null === $rest_slug) {
                continue;
            }

            $result = $this->run_async_rest_test($rest_slug);

            if (null === $result) {
                continue;
            }

            $tests[] = $this->normalize_result($slug, $result);
        }

        return $tests;
    }

    /**
     * @param array<string, mixed> $definition
     */

    private function async_rest_slug(string $slug, array $definition): ?string {
        if (in_array($slug, self::ASYNC_REST_SLUGS, true)) {
            return str_replace('_', '-', $slug);
        }

        $test = $definition['test'] ?? '';

        if (!is_string($test) || !str_contains($test, 'wp-site-health/v1/tests/')) {
            return null;
        }

        $matches = [];

        if (preg_match('~wp-site-health/v1/tests/([^/?#]+)~', $test, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */

    private function run_async_rest_test(string $rest_slug): ?array {
        $request = new \WP_REST_Request('GET', '/wp-site-health/v1/tests/' . $rest_slug);
        $response = rest_do_request($request);

        if ($response->is_error()) {
            return [
                'label' => $rest_slug,
                'status' => 'critical',
                'description' => $response->as_error()?->get_error_message() ?? '',
                'actions' => '',
                'test' => $rest_slug,
            ];
        }

        $data = $response->get_data();

        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $result
     * @return array{slug: string, label: string, status: string, description: string, actions: string}
     */

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

    private function is_development_environment(): bool {
        if (function_exists('wp_get_environment_type')) {
            $type = wp_get_environment_type();

            return in_array($type, ['local', 'development'], true);
        }

        return defined('WP_DEBUG') && \WP_DEBUG;
    }
}
