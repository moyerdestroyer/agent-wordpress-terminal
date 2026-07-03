<?php

/**
 * Bounded provider tool-call loop.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Executes provider-requested tools and asks the provider for follow-up text.
 */
final class ProviderToolLoop
{
    private const MAX_TOOL_ROUNDS = 4;

    /**
     * Run a bounded tool loop.
     *
     * @param int                              $session_id Session ID.
     * @param ProviderInterface                $provider Provider.
     * @param array<int, array<string, mixed>> $messages Provider messages.
     * @param array<string, mixed>             $result Initial provider result.
     * @param ToolRegistry                     $tool_registry Tool registry.
     * @return array{content: string, tool_calls: array<int, array<string, mixed>>, actions: list<array<string, mixed>>, model: string}
     */
    public function run(
        int $session_id,
        ProviderInterface $provider,
        array $messages,
        array $result,
        ToolRegistry $tool_registry,
    ): array {
        $content = (string) ($result['content'] ?? '');
        $tool_calls = [];
        $actions = [];
        $executor = new ProviderToolCallExecutor();
        $tool_round = 0;

        while ($tool_round < self::MAX_TOOL_ROUNDS) {
            $execution = $executor->execute($result['raw_tool_calls'] ?? [], $tool_registry, $session_id);

            if ([] === $execution['tool_calls']) {
                break;
            }

            ++$tool_round;
            $tool_calls = array_merge($tool_calls, $execution['tool_calls']);
            $actions = array_merge($actions, $this->actions_from_tool_calls($execution['tool_calls']));
            $messages[] = $executor->assistant_tool_call_message($result);
            $messages = array_merge($messages, $execution['messages']);
            $follow_up = $provider->complete($messages, $tool_registry->get_chat_completion_tools());

            if (!is_array($follow_up)) {
                $content = new ToolResultFormatter()->format_for_transcript($tool_calls, $content);
                break;
            }

            $result = new IntentToolCallEnricher()->enrich($messages, $follow_up, $tool_registry);
            $follow_up_content = trim((string) ($result['content'] ?? ''));

            if ($this->has_tool_calls($result)) {
                $content = '' !== $follow_up_content ? $follow_up_content : $content;
                continue;
            }

            $content = '' !== $follow_up_content
                ? $follow_up_content
                : new ToolResultFormatter()->format_for_transcript($tool_calls, $content);
            break;
        }

        if ($tool_round >= self::MAX_TOOL_ROUNDS && $this->has_tool_calls($result)) {
            $content = new ToolResultFormatter()->format_for_transcript($tool_calls, $content);
        }

        return [
            'content' => $content,
            'tool_calls' => $tool_calls,
            'actions' => $actions,
            'model' => (string) ($result['model'] ?? ''),
        ];
    }

    /**
     * Extract staged action payloads from successful proposal tool calls.
     *
     * @param array<int, array<string, mixed>> $tool_calls Tool call records.
     * @return list<array<string, mixed>>
     */
    private function actions_from_tool_calls(array $tool_calls): array
    {
        $actions = [];

        foreach ($tool_calls as $tool_call) {
            if ('success' !== (string) ($tool_call['status'] ?? '') || !$this->is_proposal_tool($tool_call)) {
                continue;
            }

            $output = $this->string_keyed_array_or_null($tool_call['output'] ?? null);

            if (null === $output) {
                continue;
            }

            $actions[] = $output;
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $tool_call
     */
    private function is_proposal_tool(array $tool_call): bool
    {
        return in_array(
            (string) ($tool_call['tool'] ?? ''),
            [
                'awpt/propose-content-update',
                'awpt/propose-site-settings-update',
                'awpt/propose-theme-switch',
            ],
            true,
        );
    }

    /**
     * Whether a provider result contains pending tool calls.
     *
     * @param array<string, mixed> $result Provider result.
     */
    private function has_tool_calls(array $result): bool
    {
        return is_array($result['raw_tool_calls'] ?? null) && [] !== $result['raw_tool_calls'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function string_keyed_array_or_null(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
