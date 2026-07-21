<?php

/**
 * Basic remediation hint rules (URL, content, theme).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Inventory-free remediation hints.
 */
final class RemediationBasicHints {
    /**
     * @var list<string>
     */
    private const ENVIRONMENTAL_KEYWORDS = [
        'timeout',
        'timed out',
        'loopback',
        'rest',
        'https',
        'cron',
        'curl error',
        'could not resolve',
    ];

    public function probe_url(array $context): ?array {
        $url = (string) ($context['url'] ?? '');
        $incident_kind = (string) ($context['incident_kind'] ?? '');
        $url_probe = is_array($context['url_probe'] ?? null) ? $context['url_probe'] : null;
        $should_probe = '' !== $url || in_array($incident_kind, ['preview_failure', 'js'], true);

        if (!$should_probe && null !== $url_probe) {
            $snippets = is_array($url_probe['error_snippets'] ?? null) ? $url_probe['error_snippets'] : [];
            $should_probe = [] !== $snippets;
        }

        if (!$should_probe) {
            return null;
        }

        $hint = [
            'type' => 'probe_url',
            'reason' => __(
                'Probe the affected URL server-side to capture rendered PHP or HTTP errors.',
                'agent-wordpress-terminal',
            ),
        ];

        if ('' !== $url) {
            $hint['url'] = $url;
        }

        return $hint;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */

    public function check_site_health(array $context): ?array {
        $error_type = $context['error_type'] ?? null;
        $error_type = is_string($error_type) ? $error_type : null;
        $error_text = strtolower((string) ($context['error_text'] ?? ''));
        $relevant_tests = is_array($context['relevant_tests'] ?? null) ? $context['relevant_tests'] : [];
        $environmental = $this->looks_environmental($error_type, $error_text);

        if ([] === $relevant_tests && !$environmental) {
            return null;
        }

        return [
            'type' => 'check_site_health',
            'scope' => $environmental ? 'full' : 'summary',
            'reason' => __(
                'Review Site Health for failing or recommended checks that match this error class.',
                'agent-wordpress-terminal',
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */

    public function increase_memory(array $context): ?array {
        $error_text = (string) ($context['error_text'] ?? '');
        $error_type = $context['error_type'] ?? null;
        $error_type = is_string($error_type) ? $error_type : null;
        $evidence_lines = is_array($context['evidence'] ?? null) ? $context['evidence'] : [];
        $evidence = array_values(array_map(static fn(mixed $line): string => (string) $line, $evidence_lines));
        $haystack = strtolower($error_text . "\n" . implode("\n", $evidence));
        $memory_error = str_contains($haystack, 'allowed memory size') || str_contains($haystack, 'memory exhausted');

        if (!$memory_error && ('php_fatal' !== $error_type || !str_contains($haystack, 'memory'))) {
            return null;
        }

        $environment = is_array($context['environment'] ?? null) ? $context['environment'] : [];

        return [
            'type' => 'increase_memory',
            'php_memory_limit' => (string) ($environment['php_memory_limit'] ?? ''),
            'reason' => __(
                'PHP memory limit may be too low; consider raising memory_limit in hosting or wp-config.php.',
                'agent-wordpress-terminal',
            ),
        ];
    }

    public function looks_environmental(?string $error_type, string $error_text): bool {
        if (in_array($error_type, ['js_error', 'js_unhandled_rejection'], true)) {
            return true;
        }

        foreach (self::ENVIRONMENTAL_KEYWORDS as $keyword) {
            if (str_contains($error_text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{type: string, attempted_action: string, reason: string}|null
     */
    public function fix_content(array $context): ?array {
        $attempted_action = (string) ($context['attempted_action'] ?? '');
        $fix_actions = [
            ActionOperations::CONTENT_UPDATE,
            ActionOperations::BLOCK_ATTRS_UPDATE,
            ActionOperations::NEW_POST,
            ActionOperations::SITE_SETTINGS_UPDATE,
        ];

        if (!in_array($attempted_action, $fix_actions, true)) {
            return null;
        }

        return [
            'type' => 'fix_content',
            'attempted_action' => $attempted_action,
            'reason' => sprintf(
                /* translators: %s: staged action operation */
                __('Revise the staged %s proposal or gather more context before retrying.', 'agent-wordpress-terminal'),
                $attempted_action,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */

    public function retry_action(array $context): ?array {
        $attempted_action = (string) ($context['attempted_action'] ?? '');

        if ('' === $attempted_action) {
            return null;
        }

        return [
            'type' => 'retry_action',
            'attempted_action' => $attempted_action,
            'reason' => sprintf(
                /* translators: %s: action or tool name */
                __(
                    'Retry or adjust the attempted %s after addressing the underlying cause.',
                    'agent-wordpress-terminal',
                ),
                $attempted_action,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */

    public function switch_theme(array $context): ?array {
        $primary = $this->primary_suspect($context);

        if (null === $primary || 'theme' !== ($primary['kind'] ?? '') || 'high' !== ($primary['confidence'] ?? '')) {
            return null;
        }

        return [
            'type' => 'switch_theme',
            'theme_slug' => (string) ($primary['slug'] ?? ''),
            'reason' => sprintf(
                /* translators: %s: theme slug */
                __(
                    'Error trace points at theme %s; consider staging awpt/propose-theme-switch to a default theme.',
                    'agent-wordpress-terminal',
                ),
                (string) ($primary['slug'] ?? ''),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */

    public function primary_suspect(array $context): ?array {
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
}
