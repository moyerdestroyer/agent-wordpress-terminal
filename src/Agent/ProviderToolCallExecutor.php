<?php

/**
 * Executes provider-requested read-only tools.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\MCP\Adapter;
use AWPT\Support\ProposalAbilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs discovered abilities and MCP tools requested by provider tool calls.
 */
final class ProviderToolCallExecutor {
    /** @var array<int, array<string, true>> */
    private array $read_patterns = [];

    /**
     * Execute provider-requested read-only tools.
     *
     * @param mixed        $raw_tool_calls Provider tool call payloads.
     * @param ToolRegistry $tool_registry Tool registry.
     * @return array{tool_calls: array<int, array<string, mixed>>, messages: array<int, array<string, mixed>>}
     */
    public function execute(
        mixed $raw_tool_calls,
        ToolRegistry $tool_registry,
        int $session_id,
        array $turn_context = [],
    ): array {
        if (!is_array($raw_tool_calls)) {
            return [
                'tool_calls' => [],
                'messages' => [],
            ];
        }

        $tool_calls = [];
        $messages = [];
        $visual_messages = [];

        $valid_calls = array_values(array_filter($raw_tool_calls, 'is_array'));
        $total = count($valid_calls);

        foreach ($valid_calls as $index => $raw_tool_call) {
            if (!is_array($raw_tool_call) || !$this->is_string_keyed_array($raw_tool_call)) {
                continue;
            }

            $function = is_array($raw_tool_call['function'] ?? null) ? $raw_tool_call['function'] : [];
            $function_name = (string) ($function['name'] ?? '');
            $tool_name = $tool_registry->tool_name_for_function($function_name) ?? $function_name;
            $progress_label = $this->progress_label($tool_name);
            new ChatProgress()->update($session_id, (string) ($turn_context['turn_id'] ?? ''), [
                'phase' => 'tools',
                'label' => $progress_label,
                'detail' => sprintf(__('Tool %1$d of %2$d', 'agent-wordpress-terminal'), $index + 1, $total),
                'completed' => $index,
                'total' => $total,
            ]);

            $execution = $this->execute_single_tool_call($raw_tool_call, $tool_registry, $session_id, $turn_context);
            new ChatProgress()->update($session_id, (string) ($turn_context['turn_id'] ?? ''), [
                'phase' => 'tools',
                'label' => $progress_label,
                'detail' => sprintf(__('Completed tool %1$d of %2$d', 'agent-wordpress-terminal'), $index + 1, $total),
                'completed' => $index + 1,
                'total' => $total,
            ]);
            $tool_calls[] = $execution['tool_call'];
            $messages[] = $execution['message'];

            if (is_array($execution['visual_message'] ?? null)) {
                $visual_messages[] = $execution['visual_message'];
            }
        }

        return [
            'tool_calls' => $tool_calls,
            // Tool responses must immediately follow the assistant tool call.
            // Append optional user-role visual evidence only after every response.
            'messages' => [...$messages, ...$visual_messages],
        ];
    }

    private function progress_label(string $tool_name): string {
        return match ($tool_name) {
            'awpt/list-patterns' => __('Searching patterns', 'agent-wordpress-terminal'),
            'awpt/read-pattern' => __('Reading pattern structure', 'agent-wordpress-terminal'),
            'awpt/list-content' => __('Reviewing site content and media', 'agent-wordpress-terminal'),
            'awpt/search-content' => __('Finding matching content', 'agent-wordpress-terminal'),
            'awpt/search-knowledge' => __('Searching knowledge', 'agent-wordpress-terminal'),
            'awpt/propose-new-post' => __('Validating and staging draft', 'agent-wordpress-terminal'),
            default => sprintf(__('Running %s', 'agent-wordpress-terminal'), $tool_name),
        };
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
     * @return array{tool_call: array<string, mixed>, message: array<string, mixed>, visual_message: array<string, mixed>|null}
     */
    private function execute_single_tool_call(
        array $raw_tool_call,
        ToolRegistry $tool_registry,
        int $session_id,
        array $turn_context,
    ): array {
        $provider_call_id = (string) ($raw_tool_call['id'] ?? '');
        $function = is_array($raw_tool_call['function'] ?? null) ? $raw_tool_call['function'] : [];
        $function_name = (string) ($function['name'] ?? '');
        $tool_name = $tool_registry->tool_name_for_function($function_name);
        $input = $this->decode_tool_arguments((string) ($function['arguments'] ?? '{}'));

        if (ProposalAbilities::requires_session_id($tool_name ?? '')) {
            $input['session_id'] = $session_id;
        }

        if ('awpt/propose-new-post' === $tool_name) {
            $input = new ProposalRequestContext()->enrich($session_id, $input, $turn_context);

            if ('adapted' === (string) ($input['pattern_mode'] ?? '')) {
                $pattern_name = (string) ($input['pattern_name'] ?? '');
                $read_patterns = $this->read_patterns[$session_id] ?? [];
                $input['pattern_read_verified'] = array_key_exists($pattern_name, $read_patterns);
            }
        }

        [$status, $output] = $this->run_safe_tool($tool_name, $function_name, $input, $tool_registry);

        if ('success' === $status && 'awpt/read-pattern' === $tool_name && is_array($output)) {
            $pattern_name = (string) ($output['name'] ?? $input['name'] ?? '');

            if ('' !== $pattern_name) {
                $this->read_patterns[$session_id][$pattern_name] = true;
            }
        }
        $tool = $tool_name ?? $function_name;
        $truncator = new ToolResultTruncator();
        $provider_output = is_array($output) ? $truncator->for_provider($tool, $output) : $output;
        $storage_output = is_array($output) ? $truncator->for_storage($tool, $output) : $output;
        $visual_message = 'success' === $status && is_array($output)
            ? new MediaLibraryVisualEvidence()->build($tool, $input, $output)
            : null;
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
            'visual_message' => $visual_message,
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

        $result = $tool_registry->is_ability($tool_name)
            ? new ToolExecutor()->execute($tool_name, $input)
            : new Adapter()->execute_tool($tool_name, $input);

        if (is_wp_error($result)) {
            $attribution = new \AWPT\Support\Diagnostics\ErrorPathAttributor()->from_text($result->get_error_message());
            $error_data = $result->get_error_data();

            return [
                'failed',
                array_filter(
                    [
                        'error' => $result->get_error_message(),
                        'error_code' => $result->get_error_code(),
                        'error_data' => is_array($error_data) ? $error_data : null,
                        'attribution' => $attribution,
                    ],
                    static fn(mixed $value): bool => null !== $value,
                ),
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
