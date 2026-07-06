<?php

/**
 * Formats awpt/read-site-health tool output for transcripts.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Readable Site Health summaries for transcript fallback.
 */
final class ToolResultSiteHealthFormatter {
    /**
     * @param array<string, mixed> $output
     */
    public function format(array $output): string {
        $lines = [__('Site Health', 'agent-wordpress-terminal')];
        $environment = is_array($output['environment'] ?? null) ? $output['environment'] : [];

        if ([] !== $environment) {
            $lines[] = sprintf(
                'PHP %s · memory %s · WP %s',
                (string) ($environment['php_version'] ?? '?'),
                (string) ($environment['php_memory_limit'] ?? '?'),
                (string) ($environment['wp_version'] ?? '?'),
            );
        }

        $tests = is_array($output['tests'] ?? null) ? $output['tests'] : [];

        foreach ($tests as $test) {
            if (!is_array($test)) {
                continue;
            }

            $lines[] = sprintf(
                '- %s: %s',
                (string) ($test['label'] ?? $test['slug'] ?? 'test'),
                (string) ($test['status'] ?? 'unknown'),
            );
        }

        return implode("\n", $lines);
    }
}
