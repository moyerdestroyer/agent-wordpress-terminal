<?php

/**
 * Formats tool outputs for transcript fallback responses.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable assistant text when provider follow-up is empty.
 */
final class ToolResultFormatter {
    private ToolResultContentFormatter $content;

    public function __construct(?ToolResultContentFormatter $content = null) {
        $this->content = $content ?? new ToolResultContentFormatter();
    }

    /**
     * Format successful tool calls into assistant-visible text.
     *
     * @param array<int, array<string, mixed>> $tool_calls Executed tool calls.
     * @param string                           $prefix Optional assistant prefix text.
     */
    public function format_for_transcript(array $tool_calls, string $prefix = ''): string {
        $sections = [];
        $successful_tools = [];

        foreach ($tool_calls as $tool_call) {
            if ('success' === (string) ($tool_call['status'] ?? '')) {
                $successful_tools[(string) ($tool_call['tool'] ?? '')] = true;
            }
        }

        foreach ($tool_calls as $tool_call) {
            $status = (string) ($tool_call['status'] ?? '');

            if ('success' === $status) {
                $section = $this->format_tool_call($tool_call);

                if ('' !== $section) {
                    $sections[] = $section;
                }

                continue;
            }

            if (in_array($status, ['failed', 'rejected'], true)) {
                // A validation failure the model corrected in the same turn is
                // useful internally but reads like a broken final result. The
                // complete tool history remains available in the Tools UI.
                if (array_key_exists((string) ($tool_call['tool'] ?? ''), $successful_tools)) {
                    continue;
                }

                $sections[] = $this->format_failure($tool_call);
            }
        }

        if ([] === $sections) {
            return trim($prefix);
        }

        $body = implode("\n\n", $sections);

        if ('' === trim($prefix)) {
            return $body;
        }

        return trim($prefix) . "\n\n" . $body;
    }

    /**
     * @param array<string, mixed> $tool_call Tool call record.
     */
    private function format_tool_call(array $tool_call): string {
        $tool = (string) ($tool_call['tool'] ?? '');
        $output = is_array($tool_call['output'] ?? null) ? $tool_call['output'] : [];

        $content_section = $this->content->format($tool, $output);

        if ('' !== $content_section) {
            return $content_section;
        }

        if ('awpt/diagnose-error' === $tool) {
            return $this->format_diagnosis($output);
        }

        if (ToolRegistry::is_proposal_ability($tool)) {
            return $this->format_proposal($tool, $output);
        }

        return $this->format_generic_tool($tool, $output);
    }

    /**
     * @param array<string, mixed> $tool_call
     */
    private function format_failure(array $tool_call): string {
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
     * @param array<array-key, mixed> $output
     */
    private function format_diagnosis(array $output): string {
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

    /**
     * @param array<array-key, mixed> $output
     */
    private function format_proposal(string $tool, array $output): string {
        $title = (string) ($output['title'] ?? '');
        $status = (string) ($output['status'] ?? 'proposed');
        $id = (int) ($output['id'] ?? 0);

        $summary = sprintf(
            /* translators: 1: tool name, 2: action ID, 3: action title, 4: status */
            __('%1$s staged action #%2$d: %3$s (%4$s).', 'agent-wordpress-terminal'),
            $tool,
            $id,
            '' !== $title ? $title : __('Untitled action', 'agent-wordpress-terminal'),
            $status,
        );
        $payload = is_array($output['payload'] ?? null) ? $output['payload'] : [];
        $pattern_name = (string) ($payload['pattern_name'] ?? '');
        $pattern_mode = (string) ($payload['pattern_mode'] ?? '');

        if ('' !== $pattern_name) {
            $summary .=
                ' '
                . sprintf(
                    /* translators: 1: pattern name, 2: pattern mode (adapted|prepend). */
                    __('Pattern %1$s (%2$s).', 'agent-wordpress-terminal'),
                    $pattern_name,
                    '' !== $pattern_mode ? $pattern_mode : 'adapted',
                );
        }

        $repairs = is_array($payload['repairs_applied'] ?? null) ? $payload['repairs_applied'] : [];

        if ([] === $repairs) {
            return $summary;
        }

        $lines = [$summary, __('AWPT repaired Gutenberg markup before staging:', 'agent-wordpress-terminal')];

        foreach ($repairs as $repair) {
            if (!is_array($repair)) {
                continue;
            }

            $lines[] = sprintf(
                '- %1$s %2$s: %3$s',
                (string) ($repair['block_name'] ?? __('Block', 'agent-wordpress-terminal')),
                (string) ($repair['block_path'] ?? ''),
                (string) ($repair['description'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $output
     */
    private function format_generic_tool(string $tool, array $output): string {
        // Never dump multi-kilobyte JSON (e.g. minified CSS) into the transcript.
        $keys = array_keys($output);
        $preview = [];

        foreach (array_slice($keys, 0, 8) as $key) {
            $value = $output[$key];

            if (is_string($value)) {
                $preview[$key] = mb_strlen($value, 'UTF-8') > 160 ? mb_substr($value, 0, 160, 'UTF-8') . '…' : $value;
                continue;
            }

            if (is_scalar($value) || null === $value) {
                $preview[$key] = $value;
            } elseif (is_array($value)) {
                $preview[$key] = sprintf('[%d items]', count($value));
            }
        }

        $encoded = wp_json_encode($preview);
        $encoded = is_string($encoded) ? $encoded : '{}';

        if (strlen($encoded) > 800) {
            $encoded = substr($encoded, 0, 800) . '…';
        }

        return sprintf(
            /* translators: 1: tool name, 2: compact JSON summary */
            __('Tool %1$s returned: %2$s', 'agent-wordpress-terminal'),
            $tool,
            $encoded,
        );
    }
}
