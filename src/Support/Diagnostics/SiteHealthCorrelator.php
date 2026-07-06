<?php

/**
 * Correlates Site Health tests with error categories.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Picks relevant Site Health tests for a given error type.
 */
final class SiteHealthCorrelator {
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
}
