<?php

/**
 * Runs WordPress Site Health direct tests.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Executes direct Site Health test callbacks.
 */
final class SiteHealthDirectRunner {
    /**
     * @param array<string, array<string, mixed>> $direct_definitions
     * @param list<string>                        $filter_tests
     * @return list<array{slug: string, label: string, status: string, description: string, actions: string}>
     */
    public function run(array $direct_definitions, array $filter_tests): array {
        $tests = [];
        $normalizer = new SiteHealthResultNormalizer();

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

            $tests[] = $normalizer->normalize($slug, $result);
        }

        return $tests;
    }

    private function is_development_environment(): bool {
        if (function_exists('wp_get_environment_type')) {
            $type = wp_get_environment_type();

            return in_array($type, ['local', 'development'], true);
        }

        return defined('WP_DEBUG') && \WP_DEBUG;
    }
}
