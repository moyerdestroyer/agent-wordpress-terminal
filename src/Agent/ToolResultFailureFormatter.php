<?php

/**
 * Formats failed tool calls for transcript fallback.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Readable failure summaries for tool calls.
 */
final class ToolResultFailureFormatter {
    /**
     * @param array<string, mixed> $tool_call
     */
    public function format_tool_call(array $tool_call): string {
        $tool = (string) ($tool_call['tool'] ?? '');
        $output = is_array($tool_call['output'] ?? null) ? $tool_call['output'] : [];
        $error = (string) ($output['error'] ?? $tool_call['status'] ?? 'failed');

        return sprintf(
            /* translators: 1: tool name, 2: error message */
            __('Tool %1$s failed: %2$s', 'agent-wordpress-terminal'),
            $tool,
            $error,
        );
    }

    /**
     * @param array<string, mixed> $output
     */
    public function format_diagnosis(array $output): string {
        $lines = [(string) ($output['summary'] ?? __('Diagnosis complete.', 'agent-wordpress-terminal'))];
        $suspects = is_array($output['suspects'] ?? null) ? $output['suspects'] : [];

        foreach ($suspects as $suspect) {
            if (!is_array($suspect)) {
                continue;
            }

            $lines[] = sprintf(
                '- %s %s (%s)',
                (string) ($suspect['kind'] ?? 'unknown'),
                (string) ($suspect['slug'] ?? ''),
                (string) ($suspect['confidence'] ?? ''),
            );
        }

        $remediations = is_array($output['suggested_remediations'] ?? null) ? $output['suggested_remediations'] : [];

        if ([] !== $remediations) {
            $lines[] = __('Suggested next steps:', 'agent-wordpress-terminal');

            foreach ($remediations as $hint) {
                if (!is_array($hint)) {
                    continue;
                }

                $lines[] = sprintf('- %s: %s', (string) ($hint['type'] ?? 'hint'), (string) ($hint['reason'] ?? ''));
            }
        }

        return implode("\n", $lines);
    }
}
