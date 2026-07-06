<?php

/**
 * Executes provider-requested read-only tools.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ProposalAbilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs safe AWPT abilities requested by provider tool calls.
 */
final class ProviderToolCallExecutor {
    /**
     * Execute provider-requested read-only tools.
     *
     * @param mixed        $raw_tool_calls Provider tool call payloads.
     * @param ToolRegistry $tool_registry Tool registry.
     * @return array{tool_calls: array<int, array<string, mixed>>, messages: array<int, array<string, mixed>>}
     */
    public function execute(mixed $raw_tool_calls, ToolRegistry $tool_registry, int $session_id): array {
        if (!is_array($raw_tool_calls)) {
            return [
                'tool_calls' => [],
                'messages' => [],
            ];
        }

        $tool_calls = [];
        $messages = [];

        foreach ($raw_tool_calls as $raw_tool_call) {
            if (!is_array($raw_tool_call) || !$this->is_string_keyed_array($raw_tool_call)) {
                continue;
            }

            $execution = $this->execute_single_tool_call($raw_tool_call, $tool_registry, $session_id);
            $tool_calls[] = $execution['tool_call'];
            $messages[] = $execution['message'];
        }

        return [
            'tool_calls' => $tool_calls,
            'messages' => $messages,
        ];
    }

    /**
     * Build the assistant message that requested provider tool calls.
     *
     * @param array<string, mixed> $result Provider result.
     * @return array<string, mixed>
     */
    public function assistant_tool_call_message(array $result): array {
        $message = is_array($result['message'] ?? null) ? $result['message'] : [];

        return [
            'role' => 'assistant',
            'content' => $message['content'] ?? '',
            'tool_calls' => $message['tool_calls'] ?? [],
        ];
    }

    /**
     * Execute one provider tool call.
     *
     * @param array<string, mixed> $raw_tool_call Raw provider tool call.
     * @param ToolRegistry         $tool_registry Tool registry.
     * @param int                  $session_id Current AWPT session ID.
     * @return array{tool_call: array<string, mixed>, message: array<string, mixed>}
     */
    private function execute_single_tool_call(
        array $raw_tool_call,
        ToolRegistry $tool_registry,
        int $session_id,
    ): array {
        $provider_call_id = (string) ($raw_tool_call['id'] ?? '');
        $function = is_array($raw_tool_call['function'] ?? null) ? $raw_tool_call['function'] : [];
        $function_name = (string) ($function['name'] ?? '');
        $tool_name = $tool_registry->tool_name_for_function($function_name);
        $input = $this->decode_tool_arguments((string) ($function['arguments'] ?? '{}'));

        if (ProposalAbilities::requires_session_id($tool_name ?? '')) {
            $input['session_id'] = $session_id;
        }

        [$status, $output] = $this->run_safe_tool($tool_name, $function_name, $input, $tool_registry);
        $tool = $tool_name ?? $function_name;
        $truncator = new ToolResultTruncator();
        $provider_output = is_array($output) ? $truncator->for_provider($tool, $output) : $output;
        $storage_output = is_array($output) ? $truncator->for_storage($tool, $output) : $output;
        $tool_call = [
            'tool' => $tool,
            'input' => $input,
            'output' => $storage_output,
            'status' => $status,
            'provider_call_id' => $provider_call_id,
        ];

        if ('' === $provider_call_id) {
            $provider_call_id = 'awpt_local_' . wp_generate_password(8, false);
            $tool_call['provider_call_id'] = $provider_call_id;
        }

        return [
            'tool_call' => $tool_call,
            'message' => [
                'role' => 'tool',
                'tool_call_id' => $provider_call_id,
                'content' => wp_json_encode($provider_output),
            ],
        ];
    }

    /**
     * Run a safe tool or return a rejection result.
     *
     * @param string|null          $tool_name Ability name.
     * @param string               $function_name Provider function name.
     * @param array<string, mixed> $input Tool input.
     * @param ToolRegistry         $tool_registry Tool registry.
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function run_safe_tool(
        ?string $tool_name,
        string $function_name,
        array $input,
        ToolRegistry $tool_registry,
    ): array {
        if (null === $tool_name || !$tool_registry->can_auto_execute($tool_name)) {
            return [
                'rejected',
                [
                    'error' => sprintf(
                        /* translators: %s: tool name */
                        __('Tool is not allowed for automatic execution: %s', 'agent-wordpress-terminal'),
                        $function_name,
                    ),
                ],
            ];
        }

        $result = new ToolExecutor()->execute($tool_name, $input);

        if (is_wp_error($result)) {
            $attribution = new \AWPT\Support\Diagnostics\ErrorPathAttributor()->from_text($result->get_error_message());

            return [
                'failed',
                [
                    'error' => $result->get_error_message(),
                    'error_code' => $result->get_error_code(),
                    'attribution' => $attribution,
                ],
            ];
        }

        return ['success', $result];
    }

    /**
     * Decode provider tool call arguments.
     *
     * @param string $arguments Raw JSON arguments.
     * @return array<array-key, mixed>
     */
    private function decode_tool_arguments(string $arguments): array {
        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $value Raw array.
     */
    private function is_string_keyed_array(array $value): bool {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }
}
