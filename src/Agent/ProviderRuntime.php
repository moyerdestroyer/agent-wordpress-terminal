<?php

/**
 * Provider-backed agent loop.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\MessageRepository;
use AWPT\Knowledge\KnowledgeSearchCache;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs natural language messages through the configured provider and tool loop.
 */
final class ProviderRuntime {
    private const MAX_TOOL_ROUNDS = 4;

    /**
     * Error code WordPressAIClientProvider returns when the connector has no model
     * that can perform text generation for the current request, even after AWPT's
     * own retry-without-abilities safety net.
     */
    private const NO_TEXT_GENERATION_ERROR_CODE = 'awpt_connector_no_text_generation';

    private ProviderFactory $provider_factory;

    private ProviderMessageBuilder $message_builder;

    private ToolRegistry $tool_registry;

    private IntentToolCallEnricher $intent_enricher;

    private ProviderToolCallExecutor $tool_executor;

    private ToolResultFormatter $result_formatter;

    public function __construct(
        ?ProviderFactory $provider_factory = null,
        ?ProviderMessageBuilder $message_builder = null,
        ?ToolRegistry $tool_registry = null,
    ) {
        $this->provider_factory = $provider_factory ?? new ProviderFactory();
        $this->message_builder = $message_builder ?? new ProviderMessageBuilder();
        $this->tool_registry = $tool_registry ?? new ToolRegistry();
        $this->intent_enricher = new IntentToolCallEnricher();
        $this->tool_executor = new ProviderToolCallExecutor();
        $this->result_formatter = new ToolResultFormatter();
    }

    /**
     * Get a provider-backed response.
     *
     * @param int $session_id Session ID.
     * @return array<string, mixed>
     */
    public function respond(int $session_id): array {
        $provider = $this->provider_factory->make();
        $messages = $this->message_builder->build($session_id);
        $result = $provider->complete($messages, $this->tool_registry->get_chat_completion_tools());
        $notice = '';

        if (is_wp_error($result)) {
            $failover = $this->maybe_failover($provider, $result, $messages);

            if (null !== $failover) {
                [$provider, $result, $notice] = $failover;
            }
        }

        if (is_wp_error($result)) {
            return [
                'content' => $result->get_error_message(),
                'tool_calls' => [],
                'actions' => [],
                'provider' => $provider->get_name(),
            ];
        }

        $result = $this->intent_enricher->enrich($messages, $result, $this->tool_registry);
        $loop_result = $this->run_tool_loop($session_id, $provider, $messages, $result);
        $response = [
            'content' => $loop_result['content'],
            'tool_calls' => $loop_result['tool_calls'],
            'actions' => $loop_result['actions'],
            'provider' => $provider->get_name(),
            'model' => $loop_result['model'],
        ];
        $knowledge_trace = $this->knowledge_trace($session_id);

        if (null !== $knowledge_trace) {
            $tool_calls = is_array($response['tool_calls'] ?? null) ? $response['tool_calls'] : [];
            array_unshift($tool_calls, $knowledge_trace);
            $response['tool_calls'] = $tool_calls;
        }

        if ('' !== $notice) {
            $response['content'] = trim($notice . "\n\n" . (string) $response['content']);
        }

        return $response;
    }

    /**
     * Bounded tool-call loop used by natural-language turns and diagnosis.
     *
     * @param array<int, array<string, mixed>> $messages Provider messages.
     * @param array<string, mixed>             $result Initial provider result.
     * @return array{content: string, tool_calls: array<int, array<string, mixed>>, actions: list<array<string, mixed>>, model: string}
     */
    public function run_tool_loop(
        int $session_id,
        ProviderInterface $provider,
        array $messages,
        array $result,
        ?ToolRegistry $tool_registry = null,
    ): array {
        $tool_registry ??= $this->tool_registry;
        $content = (string) ($result['content'] ?? '');
        $tool_calls = [];
        $actions = [];
        $tool_round = 0;

        while ($tool_round < self::MAX_TOOL_ROUNDS) {
            $execution = $this->tool_executor->execute($result['raw_tool_calls'] ?? [], $tool_registry, $session_id);

            if ([] === $execution['tool_calls']) {
                break;
            }

            ++$tool_round;
            $tool_calls = [...$tool_calls, ...$execution['tool_calls']];
            $actions = [...$actions, ...$this->actions_from_tool_calls($execution['tool_calls'])];
            $messages[] = $this->tool_executor->assistant_tool_call_message($result);
            $messages = array_merge($messages, $execution['messages']);
            $state = [
                'messages' => $messages,
                'result' => $result,
                'tool_calls' => $tool_calls,
                'content' => $content,
            ];
            $follow_up = $this->follow_up_round($provider, $tool_registry, $state);

            $content = $follow_up['content'];
            $result = $follow_up['result'];

            if ($follow_up['continue']) {
                continue;
            }

            break;
        }

        if ($tool_round >= self::MAX_TOOL_ROUNDS && $this->has_tool_calls($result)) {
            $content = $this->result_formatter->format_for_transcript($tool_calls, $content);
        }

        // Record failures for the open-incidents context; diagnosis is opt-in via REST.
        new DiagnosisRuntime()->record_first_failure($session_id, $tool_calls);

        return [
            'content' => $content,
            'tool_calls' => $tool_calls,
            'actions' => $actions,
            'model' => (string) ($result['model'] ?? ''),
        ];
    }

    /**
     * @param array{
     *     messages: array<int, array<string, mixed>>,
     *     result: array<string, mixed>,
     *     tool_calls: array<int, array<string, mixed>>,
     *     content: string
     * } $state
     * @return array{content: string, result: array<string, mixed>, continue: bool}
     */
    private function follow_up_round(ProviderInterface $provider, ToolRegistry $tool_registry, array $state): array {
        $follow_up = $provider->complete($state['messages'], $tool_registry->get_chat_completion_tools());

        if (!is_array($follow_up)) {
            return [
                'content' => $this->result_formatter->format_for_transcript($state['tool_calls'], $state['content']),
                'result' => $state['result'],
                'continue' => false,
            ];
        }

        $result = $this->intent_enricher->enrich($state['messages'], $follow_up, $tool_registry);
        $follow_up_content = trim((string) ($result['content'] ?? ''));

        if ($this->has_tool_calls($result)) {
            return [
                'content' => '' !== $follow_up_content ? $follow_up_content : $state['content'],
                'result' => $result,
                'continue' => true,
            ];
        }

        return [
            'content' => '' !== $follow_up_content
                ? $follow_up_content
                : $this->result_formatter->format_for_transcript($state['tool_calls'], $state['content']),
            'result' => $result,
            'continue' => false,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages Provider messages.
     * @return array{0: ProviderInterface, 1: array<string, mixed>|\WP_Error, 2: string}|null
     */
    private function maybe_failover(ProviderInterface $provider, \WP_Error $error, array $messages): ?array {
        if (
            !$provider instanceof WordPressAIClientProvider
            || self::NO_TEXT_GENERATION_ERROR_CODE !== $error->get_error_code()
        ) {
            return null;
        }

        $fallback = new OpenRouterProvider();
        $fallback_result = $fallback->complete($messages, $this->tool_registry->get_chat_completion_tools());

        $notice = sprintf(
            /* translators: %s: original connector/provider name. */
            __(
                '[AWPT] "%s" has no model available for text generation, so this reply used OpenRouter instead. Check AI connection settings.',
                'agent-wordpress-terminal',
            ),
            $provider->get_name(),
        );

        return [$fallback, $fallback_result, $notice];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function knowledge_trace(int $session_id): ?array {
        $message = trim(new MessageRepository()->latest_user_message($session_id));

        if ('' === $message) {
            return null;
        }

        $results = new KnowledgeSearchCache()->search($message, 5);

        if ([] === $results) {
            return null;
        }

        return [
            'tool' => 'awpt/knowledge-auto-retrieval',
            'input' => ['query' => $message, 'limit' => 5],
            'output' => [
                'count' => count($results),
                'results' => $results,
            ],
            'status' => 'success',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tool_calls
     * @return list<array<string, mixed>>
     */
    private function actions_from_tool_calls(array $tool_calls): array {
        $actions = [];

        foreach ($tool_calls as $tool_call) {
            if (
                'success' !== (string) ($tool_call['status'] ?? '')
                || !ToolRegistry::is_proposal_ability((string) ($tool_call['tool'] ?? ''))
            ) {
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
     * @param array<string, mixed> $result
     */
    private function has_tool_calls(array $result): bool {
        return is_array($result['raw_tool_calls'] ?? null) && [] !== $result['raw_tool_calls'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function string_keyed_array_or_null(mixed $value): ?array {
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
