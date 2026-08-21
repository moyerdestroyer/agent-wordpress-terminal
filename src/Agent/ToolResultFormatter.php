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
            if ('success' !== (string) ($tool_call['status'] ?? '')) {
                continue;
            }

            $successful_tools[(string) ($tool_call['tool'] ?? '')] = true;
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
     * Improve act unit op:none — plan said no staging.
     */
    public function format_no_change_from_plan(): string {
        return __(
            'The plan’s unit is op: none. No propose tools were offered and no change was staged.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Summarize verified evidence without replaying raw tool output when the
     * provider cannot finish the turn.
     *
     * @param array<int, array<string, mixed>> $tool_calls Executed tool calls.
     */
    public function format_incomplete_turn(
        array $tool_calls,
        string $provider_error = '',
        string $provider_error_code = '',
    ): string {
        $content = null;
        $block_count = null;
        $patterns = [];
        $successful = 0;
        $staged_action = null;

        foreach ($tool_calls as $tool_call) {
            if ('success' !== (string) ($tool_call['status'] ?? '')) {
                continue;
            }

            ++$successful;
            $tool = (string) ($tool_call['tool'] ?? '');
            $output = is_array($tool_call['output'] ?? null) ? $tool_call['output'] : [];

            if (in_array($tool, ['awpt/read-content', 'core/read-content'], true)) {
                $content = [
                    'id' => (int) ($output['id'] ?? 0),
                    'title' => trim(
                        (string) ($output['title'] ?? $output['title_rendered'] ?? $output['title_raw'] ?? ''),
                    ),
                    'type' => trim((string) ($output['type'] ?? $output['post_type'] ?? '')),
                ];
            } elseif ('awpt/read-block-tree' === $tool) {
                $block_count = (int) ($output['count'] ?? 0);
            } elseif ('awpt/read-pattern' === $tool && count($patterns) < 4) {
                $pattern = trim((string) ($output['title'] ?? $output['name'] ?? ''));

                if ('' !== $pattern) {
                    $patterns[] = $pattern;
                }
            } elseif (ToolRegistry::is_proposal_ability($tool)) {
                $staged_action = $output;
            }
        }

        $lines = [];

        if (is_array($staged_action)) {
            $action_id = (int) ($staged_action['id'] ?? 0);
            $action_title = trim((string) ($staged_action['title'] ?? ''));
            $lines[] = sprintf(
                /* translators: 1: action ID, 2: action title. */
                __('I staged proposed action #%1$d%2$s.', 'agent-wordpress-terminal'),
                $action_id,
                '' !== $action_title ? ': ' . $action_title : '',
            );
            $lines[] = __(
                'The original content has not been changed. Review the proposal and its diff, then choose Apply only if it preserves what you want.',
                'agent-wordpress-terminal',
            );
        } elseif (is_array($content)) {
            $identity = sprintf(
                '#%1$d %2$s%3$s',
                $content['id'],
                '' !== $content['title'] ? $content['title'] : __('(untitled)', 'agent-wordpress-terminal'),
                '' !== $content['type'] ? sprintf(' (%s)', $content['type']) : '',
            );
            $details = [];

            if (null !== $block_count) {
                $details[] = sprintf(
                    /* translators: %d: Gutenberg block count. */
                    1 === $block_count
                        ? __('%d block', 'agent-wordpress-terminal')
                        : __('%d blocks', 'agent-wordpress-terminal'),
                    $block_count,
                );
            }

            if ([] !== $patterns) {
                $details[] = sprintf(
                    /* translators: %s: comma-separated pattern titles. */
                    __('compared with %s', 'agent-wordpress-terminal'),
                    implode(', ', array_unique($patterns)),
                );
            }

            $lines[] = sprintf(
                /* translators: 1: content identity, 2: evidence details. */
                __('I found %1$s%2$s.', 'agent-wordpress-terminal'),
                $identity,
                [] !== $details ? ' — ' . implode('; ', $details) : '',
            );
        } elseif ($successful > 0) {
            $lines[] = sprintf(
                /* translators: %d: completed tool call count. */
                1 === $successful
                    ? __('I gathered %d verified evidence result.', 'agent-wordpress-terminal')
                    : __('I gathered %d verified evidence results.', 'agent-wordpress-terminal'),
                $successful,
            );
        }

        if (!is_array($staged_action)) {
            $lines[] = '' !== trim($provider_error)
                ? $this->provider_failure_summary($provider_error, $provider_error_code)
                : __(
                    'This turn ran out of time before a final answer could be completed, so no change was staged. Please retry; the completed evidence remains available in the Evidence details.',
                    'agent-wordpress-terminal',
                );
        }

        if ('' !== trim($provider_error) && current_user_can('manage_options')) {
            $lines[] = sprintf(
                /* translators: %s: provider error message. */
                __('Provider detail: %s', 'agent-wordpress-terminal'),
                $provider_error,
            );
        }

        return implode("\n\n", $lines);
    }

    private function provider_failure_summary(string $error, string $code): string {
        if (preg_match('/timed? out|cURL error 28/i', $error) === 1) {
            return __(
                'The AI provider timed out before it could finish the answer, so no change was staged. Please retry; the completed evidence remains available in the Evidence details.',
                'agent-wordpress-terminal',
            );
        }

        if ('awpt_provider_request_failed' === $code || str_contains($error, 'Provider request failed')) {
            return __(
                'The AI provider rejected the request before it could finish the answer, so no change was staged. The completed evidence remains available in the Evidence details.',
                'agent-wordpress-terminal',
            );
        }

        return __(
            'The AI provider could not finish the answer, so no change was staged. The completed evidence remains available in the Evidence details.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * @param array<string, mixed> $tool_call Tool call record.
     */
    private function format_tool_call(array $tool_call): string {
        $tool = (string) ($tool_call['tool'] ?? '');
        $raw_output = $tool_call['output'] ?? null;

        if (!is_array($raw_output)) {
            return $this->format_generic_value($tool, $raw_output);
        }

        $output = $raw_output;

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

    private function format_generic_value(string $tool, mixed $output): string {
        $encoded = wp_json_encode($this->preview_scalar($output));
        $encoded = is_string($encoded) ? $encoded : 'null';

        return sprintf(
            /* translators: 1: tool name, 2: compact JSON value */
            __('Tool %1$s returned: %2$s', 'agent-wordpress-terminal'),
            $tool,
            $encoded,
        );
    }

    /**
     * @param array<string, mixed> $tool_call
     */
    private function format_failure(array $tool_call): string {
        $tool = (string) ($tool_call['tool'] ?? '');
        $output = is_array($tool_call['output'] ?? null) ? $tool_call['output'] : [];

        // Provider-shaped failures already promote fix / retry_with.
        if (false === ($output['ok'] ?? null) && isset($output['error'])) {
            $lines = [
                sprintf(
                    /* translators: 1: tool name, 2: error message */
                    __('Tool %1$s failed: %2$s', 'agent-wordpress-terminal'),
                    $tool,
                    (string) $output['error'],
                ),
            ];
            $fix = trim((string) ($output['fix'] ?? ''));

            if ('' !== $fix) {
                $lines[] = __('Fix:', 'agent-wordpress-terminal') . ' ' . $fix;
            }

            return implode("\n", $lines);
        }

        $error = (string) ($output['error'] ?? $tool_call['status'] ?? 'failed');
        $error_data = is_array($output['error_data'] ?? null) ? $output['error_data'] : [];
        $feedback = is_array($error_data['agent_feedback'] ?? null) ? $error_data['agent_feedback'] : [];
        $summary = trim((string) ($feedback['summary'] ?? ''));
        $recovery = trim((string) ($error_data['recovery'] ?? ''));

        if ('' !== $summary) {
            $error .= ' ' . $summary;
        }

        $lines = [
            sprintf(
                /* translators: 1: tool name, 2: error message */
                __('Tool %1$s failed: %2$s', 'agent-wordpress-terminal'),
                $tool,
                $error,
            ),
        ];

        if ('' !== $recovery) {
            $lines[] = __('Fix:', 'agent-wordpress-terminal') . ' ' . $recovery;
        }

        return implode("\n", $lines);
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
            $rendered = $this->preview_scalar($output[$key] ?? null);

            if (null !== $rendered) {
                $preview[(string) $key] = $rendered;
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

    private function preview_scalar(mixed $value): bool|float|int|string|null {
        if (is_string($value)) {
            return mb_strlen($value, 'UTF-8') > 160 ? mb_substr($value, 0, 160, 'UTF-8') . '…' : $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
            return $value;
        }

        if (is_array($value)) {
            return sprintf('[%d items]', count($value));
        }

        return null;
    }
}
