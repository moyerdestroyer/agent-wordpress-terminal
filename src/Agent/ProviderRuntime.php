<?php

/**
 * Provider-backed agent loop.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\MessageRepository;
use AWPT\Database\ProviderCallRepository;
use AWPT\Knowledge\KnowledgeSearchCache;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs natural language messages through the configured provider and tool loop.
 */
final class ProviderRuntime {
    /** Includes the initial response and every response after a tool result. */
    private const MAX_PROVIDER_COMPLETIONS = 6;

    private const TURN_WALL_SECONDS = 60;

    private const CONTENT_TURN_WALL_SECONDS = 120;

    /**
     * Error code WordPressAIClientProvider returns when the connector has no model
     * that can perform text generation for the current request, even after AWPT's
     * own retry-without-abilities safety net.
     */
    private const NO_TEXT_GENERATION_ERROR_CODE = 'awpt_connector_no_text_generation';

    private ProviderFactory $provider_factory;

    private ProviderMessageBuilder $message_builder;

    private ToolRegistry $tool_registry;

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
        $this->tool_executor = new ProviderToolCallExecutor();
        $this->result_formatter = new ToolResultFormatter();
    }

    /**
     * Get a provider-backed response.
     *
     * @param int $session_id Session ID.
     * @return array<string, mixed>
     */
    public function respond(int $session_id, array $turn_context = []): array {
        $provider = $this->provider_factory->make();
        $messages = $this->message_builder->build($session_id);
        $messages = $this->add_attachment_evidence($messages, $turn_context['attachments'] ?? []);
        $budget = new GenerationBudget();
        $message = new MessageRepository()->latest_user_message($session_id);
        $budget_tokens = $budget->for_message($message);
        $turn_wall_seconds = $budget->is_content_request($message)
            ? self::CONTENT_TURN_WALL_SECONDS
            : self::TURN_WALL_SECONDS;
        $started_at = microtime(true);
        $turn_id = (string) ($turn_context['turn_id'] ?? '');
        new ChatProgress()->update($session_id, $turn_id, [
            'phase' => 'planning',
            'label' => __('Planning response', 'agent-wordpress-terminal'),
            'detail' => sprintf(__('Contacting %s…', 'agent-wordpress-terminal'), $provider->get_name()),
        ]);
        $result = $provider->complete($messages, $this->tool_registry->get_chat_completion_tools(), [
            'session_id' => $session_id,
            'max_completion_tokens' => $budget_tokens,
            'timeout' => $turn_wall_seconds,
        ]);
        $this->record_provider_call($session_id, [
            'provider' => $provider->get_name(),
            'tool_round' => 0,
            'budget' => $budget_tokens,
            'started_at' => $started_at,
            'result' => $result,
            'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
        ]);
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

        $loop_result = $this->run_tool_loop($session_id, $provider, $messages, $result, [
            'turn_started_at' => $started_at,
            'turn_wall_seconds' => $turn_wall_seconds,
            'turn_context' => $turn_context,
        ]);

        $content = trim((string) $loop_result['content']);
        $tool_calls = is_array($loop_result['tool_calls'] ?? null) ? $loop_result['tool_calls'] : [];
        $knowledge_trace = $this->knowledge_trace($session_id);

        if (null !== $knowledge_trace) {
            array_unshift($tool_calls, $knowledge_trace);
        }

        // Knowledge auto-retrieval is ambient context, not a substitute for a reply.
        if ('' === $content) {
            $content = $this->empty_reply_fallback($tool_calls);
        }

        $response = [
            'content' => $content,
            'tool_calls' => $tool_calls,
            'actions' => $loop_result['actions'],
            'provider' => $provider->get_name(),
            'model' => $loop_result['model'],
        ];
        $response = array_merge($response, $this->proposal_response_metadata($loop_result['actions']));

        if ('' !== $notice) {
            $response['content'] = trim($notice . "\n\n" . $response['content']);
        }

        return $response;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @return array<string, mixed>
     */
    private function proposal_response_metadata(array $actions): array {
        $removed = [];
        $revised_action_id = 0;
        $revision_kind = '';

        foreach ($actions as $action) {
            if (is_array($action['removed_action_ids'] ?? null)) {
                $removed = [...$removed, ...array_map('intval', $action['removed_action_ids'])];
            }

            if ((int) ($action['revised_action_id'] ?? 0) > 0) {
                $revised_action_id = (int) $action['revised_action_id'];
                $revision_kind = sanitize_key((string) ($action['revision_kind'] ?? ''));
            }
        }

        return [
            'removed_action_ids' => array_values(array_unique(array_filter($removed))),
            'revised_action_id' => $revised_action_id > 0 ? $revised_action_id : null,
            'revision_kind' => $revision_kind,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tool_calls
     */
    private function empty_reply_fallback(array $tool_calls): string {
        $real_tools = array_values(array_filter(
            $tool_calls,
            static fn(array $call): bool => 'awpt/knowledge-auto-retrieval' !== (string) ($call['tool'] ?? ''),
        ));

        if ([] !== $real_tools) {
            $formatted = trim($this->result_formatter->format_for_transcript($real_tools, ''));

            if ('' !== $formatted) {
                return $formatted;
            }
        }

        return __(
            'The model returned no text for this turn. Try again, or check the AI provider/model settings if this keeps happening.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Bounded tool-call loop used by natural-language turns and diagnosis.
     *
     * @param array<int, array<string, mixed>> $messages Provider messages.
     * @param array<string, mixed>             $result Initial provider result.
     * @return array{content: string, tool_calls: array<int, array<string, mixed>>, actions: list<array<string, mixed>>, model: string, messages: array<int, array<string, mixed>>}
     */
    public function run_tool_loop(
        int $session_id,
        ProviderInterface $provider,
        array $messages,
        array $result,
        array $options = [],
    ): array {
        $tool_registry = $options['tool_registry'] ?? $this->tool_registry;

        if (!$tool_registry instanceof ToolRegistry) {
            $tool_registry = $this->tool_registry;
        }
        $content = (string) ($result['content'] ?? '');
        $tool_calls = [];
        $actions = [];
        $provider_completions = 1;
        $tool_round = 0;
        $proposal_failures = 0;
        $corrective_replan_sent = false;
        $recovery_stall_nudge_sent = false;
        $discovery_nudge_sent = false;
        $turn_started_at = is_float($options['turn_started_at'] ?? null)
            ? $options['turn_started_at']
            : microtime(true);
        $turn_context = is_array($options['turn_context'] ?? null) ? $options['turn_context'] : [];
        $formatted_after_success = false;

        while ($provider_completions <= self::MAX_PROVIDER_COMPLETIONS) {
            $execution = $this->tool_executor->execute(
                $result['raw_tool_calls'] ?? [],
                $tool_registry,
                $session_id,
                $turn_context,
            );

            if ([] === $execution['tool_calls']) {
                break;
            }

            ++$tool_round;
            $tool_calls = [...$tool_calls, ...$execution['tool_calls']];
            $actions = [...$actions, ...$this->actions_from_tool_calls($execution['tool_calls'])];
            $messages[] = $this->tool_executor->assistant_tool_call_message($result);
            $messages = array_merge($messages, $execution['messages']);

            foreach ($execution['tool_calls'] as $call) {
                if (
                    'awpt/propose-new-post' === (string) ($call['tool'] ?? '')
                    && 'success' !== (string) ($call['status'] ?? '')
                ) {
                    ++$proposal_failures;
                }
            }

            if ($this->has_successful_new_post_proposal($execution['tool_calls'])) {
                new ChatProgress()->update($session_id, (string) ($turn_context['turn_id'] ?? ''), [
                    'phase' => 'preview',
                    'label' => __('Preparing preview', 'agent-wordpress-terminal'),
                    'detail' => __('The staged proposal passed validation.', 'agent-wordpress-terminal'),
                ]);
                $content = $this->result_formatter->format_for_transcript($tool_calls, $content);
                $formatted_after_success = true;
                break;
            }

            if ($proposal_failures >= 2 || $provider_completions >= self::MAX_PROVIDER_COMPLETIONS) {
                if ($proposal_failures >= 2) {
                    $content = __(
                        'I could not stage the proposal after one corrected attempt. The validation failures are preserved below so the next attempt can use verified site evidence.',
                        'agent-wordpress-terminal',
                    );
                }
                break;
            }

            if ($proposal_failures > 0 && !$corrective_replan_sent) {
                $messages[] = [
                    'role' => 'system',
                    'content' => 'The staging attempt failed validation. Reconsider the approach and make at most one corrected staging attempt. Read the complete structured error_data: address every listed issue, use exact available identifiers, and call the recommended read tools when evidence is missing. You retain the full tool set. Do not repeat unchanged arguments or ask the user to choose routine creative details you can decide.',
                ];
                $corrective_replan_sent = true;
            }

            if (0 === $proposal_failures && count($tool_calls) >= 6 && !$discovery_nudge_sent) {
                $messages[] = [
                    'role' => 'system',
                    'content' => 'You now have substantial site evidence. Unless a concrete identifier required for staging is still missing, stop broad discovery and use your judgment to compose and stage the requested proposal now. Do not repeat searches or pattern reads already completed in this turn.',
                ];
                $discovery_nudge_sent = true;
            }

            $state = [
                'session_id' => $session_id,
                'tool_round' => $tool_round,
                'messages' => $messages,
                'result' => $result,
                'tool_calls' => $tool_calls,
                'content' => $content,
                'turn_started_at' => $turn_started_at,
                'turn_wall_seconds' => (int) ($options['turn_wall_seconds'] ?? self::TURN_WALL_SECONDS),
                'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
            ];
            new ChatProgress()->update($session_id, (string) ($turn_context['turn_id'] ?? ''), [
                'phase' => 'composing',
                'label' => __('Composing response', 'agent-wordpress-terminal'),
                'detail' => sprintf(
                    __('Using evidence from %d completed tool calls…', 'agent-wordpress-terminal'),
                    count($tool_calls),
                ),
            ]);
            $follow_up = $this->follow_up_round($provider, $tool_registry, $state);
            ++$provider_completions;

            $content = $follow_up['content'];
            $result = $follow_up['result'];

            if ($follow_up['continue']) {
                continue;
            }

            if (
                $proposal_failures > 0
                && !$recovery_stall_nudge_sent
                && $provider_completions < self::MAX_PROVIDER_COMPLETIONS
            ) {
                if ('' !== trim($content)) {
                    $messages[] = ['role' => 'assistant', 'content' => $content];
                }

                $messages[] = [
                    'role' => 'system',
                    'content' => 'The requested creation task is still unresolved: explanatory prose did not stage a proposal. Do not delegate available AWPT tool calls to the admin or ask them to choose routine creative details. Use the exact identifiers and recommended_next_tools already present in tool error_data, gather any missing evidence, and continue the task now. You still choose the composition; do not invent identifiers.',
                ];
                $recovery_stall_nudge_sent = true;
                $state = [
                    'session_id' => $session_id,
                    'tool_round' => $tool_round,
                    'messages' => $messages,
                    'result' => $result,
                    'tool_calls' => $tool_calls,
                    'content' => $content,
                    'turn_started_at' => $turn_started_at,
                    'turn_wall_seconds' => (int) ($options['turn_wall_seconds'] ?? self::TURN_WALL_SECONDS),
                    'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
                ];
                $follow_up = $this->follow_up_round($provider, $tool_registry, $state);
                ++$provider_completions;
                $content = $follow_up['content'];
                $result = $follow_up['result'];

                if ($follow_up['continue']) {
                    continue;
                }
            }

            break;
        }

        if (!$formatted_after_success && [] !== $tool_calls) {
            $content = $this->result_formatter->format_for_transcript($tool_calls, $content);
        }

        // Record unresolved failures for the open-incidents context; diagnosis is opt-in via REST.
        new DiagnosisRuntime()->record_first_failure($session_id, $this->unresolved_tool_failures($tool_calls));

        return [
            'content' => $content,
            'tool_calls' => $tool_calls,
            'actions' => $actions,
            'model' => (string) ($result['model'] ?? ''),
            'messages' => $messages,
        ];
    }

    /**
     * @param array{
     *     session_id: int,
     *     tool_round: int,
     *     messages: array<int, array<string, mixed>>,
     *     result: array<string, mixed>,
     *     tool_calls: array<int, array<string, mixed>>,
     *     content: string,
     *     turn_started_at: float,
     *     turn_wall_seconds: int,
     *     turn_id: string
     * } $state
     * @return array{content: string, result: array<string, mixed>, continue: bool}
     */
    private function follow_up_round(ProviderInterface $provider, ToolRegistry $tool_registry, array $state): array {
        $message = new MessageRepository()->latest_user_message($state['session_id']);
        $budget_tokens = new GenerationBudget()->for_message($message, count($state['tool_calls']));
        $started_at = microtime(true);
        $completion_budget = $budget_tokens;
        $remaining = $state['turn_wall_seconds'] - (int) ceil(microtime(true) - $state['turn_started_at']);

        if ($remaining < 5) {
            return [
                'content' => $this->result_formatter->format_for_transcript($state['tool_calls'], __(
                    'The turn reached its time budget before the model finished. The completed tool results are preserved below.',
                    'agent-wordpress-terminal',
                )),
                'result' => $state['result'],
                'continue' => false,
            ];
        }

        $follow_up = $provider->complete($state['messages'], $tool_registry->get_chat_completion_tools(), [
            'session_id' => $state['session_id'],
            'max_completion_tokens' => $completion_budget,
            'tool_choice' => 'auto',
            'timeout' => min(120, max(5, $remaining)),
        ]);
        $this->record_provider_call($state['session_id'], [
            'provider' => $provider->get_name(),
            'tool_round' => count($state['tool_calls']),
            'budget' => $completion_budget,
            'started_at' => $started_at,
            'result' => $follow_up,
            'turn_id' => $state['turn_id'],
        ]);

        if (!is_array($follow_up)) {
            $failure = is_wp_error($follow_up)
                ? sprintf(
                    __('The model request failed after discovery: %s', 'agent-wordpress-terminal'),
                    $follow_up->get_error_message(),
                )
                : $state['content'];

            return [
                // Finalization formats the tool results once. Returning an
                // already-formatted transcript here duplicated every read.
                'content' => $failure,
                'result' => $state['result'],
                'continue' => false,
            ];
        }

        $result = $follow_up;
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

    /** @param array<int, array<string, mixed>> $tool_calls */
    private function has_successful_new_post_proposal(array $tool_calls): bool {
        foreach ($tool_calls as $tool_call) {
            if (
                'awpt/propose-new-post' === (string) ($tool_call['tool'] ?? '')
                && 'success' === (string) ($tool_call['status'] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A later successful call to the same tool resolves its earlier failures.
     *
     * @param array<int, array<string, mixed>> $tool_calls
     * @return array<int, array<string, mixed>>
     */
    private function unresolved_tool_failures(array $tool_calls): array {
        $successful_after = [];
        /** @var array<int, array<string, mixed>> $unresolved */
        $unresolved = [];

        foreach (array_reverse($tool_calls) as $tool_call) {
            $tool = (string) ($tool_call['tool'] ?? '');
            $status = (string) ($tool_call['status'] ?? '');

            if ('success' === $status) {
                $successful_after[$tool] = true;
                continue;
            }

            if ('failed' === $status && !array_key_exists($tool, $successful_after)) {
                $unresolved[] = $tool_call;
            }
        }

        return array_reverse($unresolved);
    }

    /** @param array<string, mixed> $call */
    private function record_provider_call(int $session_id, array $call): void {
        $result = $call['result'] ?? null;
        if (!is_array($result) && !is_wp_error($result)) {
            return;
        }
        $started_at = is_float($call['started_at'] ?? null) ? $call['started_at'] : microtime(true);
        $call['duration_ms'] = (int) round((microtime(true) - $started_at) * 1000);
        new ProviderCallRepository()->store($session_id, $call);
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

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function add_attachment_evidence(array $messages, mixed $attachments): array {
        if (!is_array($attachments) || [] === $attachments) {
            return $messages;
        }

        for ($index = count($messages) - 1; $index >= 0; --$index) {
            if (
                'user' !== (string) ($messages[$index]['role'] ?? '')
                || !is_string($messages[$index]['content'] ?? null)
            ) {
                continue;
            }

            $parts = [['type' => 'text', 'text' => (string) $messages[$index]['content']]];

            foreach ($attachments as $attachment) {
                if (is_array($attachment) && '' !== (string) ($attachment['url'] ?? '')) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => (string) $attachment['url']]];
                }
            }

            $messages[$index]['content'] = $parts;
            break;
        }

        return $messages;
    }
}
