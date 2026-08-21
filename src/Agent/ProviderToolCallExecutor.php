<?php

/**
 * Executes provider-requested read-only tools.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ActionRepository;
use AWPT\MCP\Adapter;
use AWPT\Support\AiLogger;
use AWPT\Support\ArrayKey;
use AWPT\Support\ImproveActProposeHydrator;
use AWPT\Support\ProposalAbilities;
use AWPT\Support\TurnToolEvidence;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs discovered abilities and MCP tools requested by provider tool calls.
 */
final class ProviderToolCallExecutor {
    /** @var array<int, array<string, true>> */
    private array $read_patterns = [];

    /** @var array<int, array<string, true>> */
    private array $knowledge_chunks = [];

    /** @var array<int, array<string, true>> */
    private array $knowledge_queries = [];

    /** @var array<int, list<string>> */
    private array $knowledge_query_texts = [];

    /** @param list<array<array-key, mixed>>|array<array-key, mixed> $items */
    public function seed_knowledge_chunks(int $session_id, array $items): void {
        foreach (ArrayKey::list_of_maps($items) as $item) {
            $chunk_id = (string) ($item['chunk_id'] ?? '');

            if ('' === $chunk_id) {
                continue;
            }

            $this->knowledge_chunks[$session_id][$chunk_id] = true;
        }
    }

    /** @param array<string, mixed> $context */
    public function seed_knowledge_context(int $session_id, array $context): void {
        $items = is_array($context['items'] ?? null) ? $context['items'] : [];
        $this->seed_knowledge_chunks($session_id, $items);
        $fingerprint = (string) ($context['query_fingerprint'] ?? '');

        if ('' !== $fingerprint) {
            $this->knowledge_queries[$session_id][$fingerprint] = true;
        }

        $query = trim((string) ($context['query'] ?? ''));

        if ('' !== $query) {
            $this->knowledge_query_texts[$session_id][] = $query;
        }
    }

    /**
     * Execute provider-requested tools (parallel-safe reads batched; proposals serial).
     *
     * @param mixed        $raw_tool_calls Provider tool call payloads.
     * @param ToolRegistry $tool_registry Tool registry.
     * @return array{
     *     tool_calls: array<int, array<string, mixed>>,
     *     messages: array<int, array<string, mixed>>,
     *     parallel_batch_size: int
     * }
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
                'parallel_batch_size' => 0,
            ];
        }

        $items = [];
        $parallel_safe_count = 0;
        $parallelism = new ToolParallelism();

        foreach (array_keys($raw_tool_calls) as $key) {
            $call = ArrayKey::as_map_or_null(ArrayKey::passthrough($raw_tool_calls[$key] ?? null));

            if (null === $call) {
                continue;
            }

            $function = ArrayKey::as_map($call['function'] ?? null);
            $function_name = (string) ($function['name'] ?? '');
            $tool_name = $tool_registry->tool_name_for_function($function_name) ?? $function_name;
            $index = count($items);

            if ($parallelism->is_parallel_safe($tool_name)) {
                ++$parallel_safe_count;
            }

            $items[] = [
                'index' => $index,
                'tool_name' => $tool_name,
                'raw' => $call,
            ];
        }

        $all_items = $items;
        $proposal_items = array_values(array_filter($items, static fn(array $item): bool => ProposalAbilities::is_proposal(
            $item['tool_name'],
        )));
        $proposal_signatures = [];

        foreach ($proposal_items as $item) {
            $proposal_signatures[$this->proposal_signature($item)][] = $item;
        }

        if (count($proposal_signatures) > 1) {
            return $this->reject_non_atomic_proposal_batch($all_items);
        }

        // Provider gateways occasionally repeat one mutation with different
        // card copy or call IDs. That is one edit, not competing staging
        // intent: execute the best copy once, then acknowledge every call ID.
        if (1 === count($proposal_signatures) && count($proposal_items) > 1) {
            $canonical = $this->canonical_proposal_item(array_values($proposal_signatures)[0], $session_id);
            $canonical_index = $canonical['index'];
            $items = array_values(array_filter(
                $items,
                static fn(array $item): bool => (
                    !ProposalAbilities::is_proposal($item['tool_name'])
                    || $item['index'] === $canonical_index
                ),
            ));

            // ToolBatchRunner reassembles 0..count-1. After dropping duplicate
            // proposals the kept row may still carry its original provider index.
            foreach ($items as $reindex => $item) {
                $items[$reindex]['index'] = $reindex;
            }
        }

        $total = count($items);
        $turn_id = (string) ($turn_context['turn_id'] ?? '');
        $phase = sanitize_key((string) ($turn_context['progress_phase'] ?? 'tools'));
        $phase = '' !== $phase ? $phase : 'tools';

        if ($total > 1) {
            new ChatProgress()->update($session_id, $turn_id, [
                'phase' => $phase,
                'label' => __('Running tools', 'agent-wordpress-terminal'),
                'detail' => sprintf(
                    /* translators: 1: tool count, 2: parallel-safe count */
                    __('Batch of %1$d tool(s) (%2$d parallel-safe)…', 'agent-wordpress-terminal'),
                    $total,
                    $parallel_safe_count,
                ),
                'completed' => 0,
                'total' => $total,
                'diagnostics' => [
                    'parallel_batch_size' => $parallel_safe_count,
                    'tool_batch_total' => $total,
                ],
            ]);
        }

        $results = new ToolBatchRunner($parallelism)->run(
            $items,
            function (array $raw, int $index) use ($tool_registry, $session_id, $turn_context): array {
                unset($index);

                return $this->execute_single_tool_call($raw, $tool_registry, $session_id, $turn_context);
            },
            function (int $completed, int $batch_total, string $tool, string $wave_kind) use (
                $session_id,
                $turn_id,
                $phase,
                $parallel_safe_count,
            ): void {
                unset($wave_kind);
                new ChatProgress()->update($session_id, $turn_id, [
                    'phase' => $phase,
                    'label' => $this->progress_label($tool),
                    'detail' => sprintf(
                        __('Completed tool %1$d of %2$d', 'agent-wordpress-terminal'),
                        $completed,
                        $batch_total,
                    ),
                    'completed' => $completed,
                    'total' => $batch_total,
                    'diagnostics' => [
                        'parallel_batch_size' => $parallel_safe_count,
                        'tool_batch_total' => $batch_total,
                    ],
                ]);
            },
        );

        $tool_calls = [];
        $message_content_by_call_id = [];
        $canonical_proposal_content = null;
        $visual_messages = [];

        foreach ($results as $execution) {
            $tool_calls[] = $execution['tool_call'];
            $message = $execution['message'];
            $call_id = (string) ($message['tool_call_id'] ?? '');
            $message_content_by_call_id[$call_id] = (string) ($message['content'] ?? '');

            if (ProposalAbilities::is_proposal((string) ($execution['tool_call']['tool'] ?? ''))) {
                $canonical_proposal_content = (string) ($message['content'] ?? '');
            }

            if (is_array($execution['visual_message'] ?? null)) {
                $visual_messages[] = $execution['visual_message'];
            }
        }

        $messages = [];

        foreach ($all_items as $item) {
            $raw = $item['raw'];
            $provider_call_id = (string) ($raw['id'] ?? '');
            $content = $message_content_by_call_id[$provider_call_id] ?? null;

            if (null === $content && ProposalAbilities::is_proposal($item['tool_name'])) {
                $content = $canonical_proposal_content;
            }

            if (null === $content) {
                continue;
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $provider_call_id,
                'content' => $content,
            ];
        }

        return [
            'tool_calls' => $tool_calls,
            // Tool responses must immediately follow the assistant tool call.
            // Append optional user-role visual evidence only after every response.
            'messages' => [...$messages, ...$visual_messages],
            'parallel_batch_size' => $parallel_safe_count,
        ];
    }

    /**
     * Identity of the staged document change, not the action-card prose.
     *
     * Title, description, and other runtime-injected fields are dropped so a
     * provider that repeats one batch with different copy is not treated as
     * competing mutations. Unknown keys stay in the hash.
     *
     * @param array{index: int, tool_name: string, raw: array<string, mixed>} $item
     */
    private function proposal_signature(array $item): string {
        $arguments = $this->proposal_arguments($item);
        $encoded = wp_json_encode($this->canonicalize_value($this->mutation_arguments($arguments)));

        return $item['tool_name'] . '|' . (is_string($encoded) ? $encoded : '{}');
    }

    /**
     * @param list<array{index: int, tool_name: string, raw: array<string, mixed>}> $items
     * @return array{index: int, tool_name: string, raw: array<string, mixed>}
     */
    private function canonical_proposal_item(array $items, int $session_id): array {
        foreach ($items as $item) {
            if ($session_id > 0 && $this->proposal_session_id($item) === $session_id) {
                return $item;
            }
        }

        foreach ($items as $item) {
            if ($this->proposal_session_id($item) > 0) {
                return $item;
            }
        }

        return $items[0];
    }

    /** @param array{index: int, tool_name: string, raw: array<string, mixed>} $item */
    private function proposal_session_id(array $item): int {
        return (int) ($this->proposal_arguments($item)['session_id'] ?? 0);
    }

    /**
     * @param array{index: int, tool_name: string, raw: array<string, mixed>} $item
     * @return array<string, mixed>
     */
    private function proposal_arguments(array $item): array {
        $function = ArrayKey::as_map($item['raw']['function'] ?? null);

        return $this->decode_tool_arguments((string) ($function['arguments'] ?? '{}'));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function mutation_arguments(array $arguments): array {
        foreach ([
            'title',
            'description',
            'affected',
            'pattern_unfit_code',
            'pattern_fallback_reason',
            'proposal_manifest',
            'decision_trace',
            'session_id',
            'turn_id',
            'proposal_key',
            'presentation_requires_h1',
        ] as $key) {
            unset($arguments[$key]);
        }

        return $arguments;
    }

    private function canonicalize_value(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize_value(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize_value($item);
        }

        return $value;
    }

    /**
     * Reject an entire provider batch before mutation when it contains competing
     * proposals. Applying the first proposal would immediately make every later
     * content proposal stale and can leave an auto-apply surface partially saved.
     *
     * @param list<array{index: int, tool_name: string, raw: array<string, mixed>}> $items
     * @return array{
     *     tool_calls: array<int, array<string, mixed>>,
     *     messages: array<int, array<string, mixed>>,
     *     parallel_batch_size: int
     * }
     */
    private function reject_non_atomic_proposal_batch(array $items): array {
        $requested_tools = array_values(array_map(static fn(array $item): string => $item['tool_name'], $items));
        $tool_calls = [];
        $messages = [];

        foreach ($items as $item) {
            $raw = $item['raw'];
            $function = ArrayKey::as_map($raw['function'] ?? null);
            $provider_call_id = (string) ($raw['id'] ?? '');

            if ('' === $provider_call_id) {
                $provider_call_id = 'awpt_local_' . wp_generate_password(8, false);
            }

            $output = [
                'error' => __(
                    'Multiple staged proposals were requested in one model response. Consolidate the page change into one atomic proposal before anything is staged.',
                    'agent-wordpress-terminal',
                ),
                'error_code' => 'awpt_multiple_proposals',
                'error_data' => [
                    'requested_tools' => $requested_tools,
                    'recommended_next_tools' => [
                        [
                            'tool' => 'awpt/propose-content-update',
                            'reason' => 'Use one complete content update when the page needs both insertions and coordinated existing-block changes.',
                        ],
                        [
                            'tool' => 'awpt/propose-block-batch-update',
                            'reason' => 'Use one block batch only when its supported operations fully express the change.',
                        ],
                    ],
                ],
            ];
            $tool_calls[] = [
                'tool' => $item['tool_name'],
                'input' => $this->decode_tool_arguments((string) ($function['arguments'] ?? '{}')),
                'output' => $output,
                'status' => 'failed',
                'provider_call_id' => $provider_call_id,
            ];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $provider_call_id,
                'content' => $this->encoded_tool_output($output),
            ];
        }

        return [
            'tool_calls' => $tool_calls,
            'messages' => $messages,
            'parallel_batch_size' => 0,
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
        $function = ArrayKey::as_map($raw_tool_call['function'] ?? null);
        $function_name = (string) ($function['name'] ?? '');
        $tool_name = $tool_registry->tool_name_for_function($function_name);
        $input = $this->decode_tool_arguments((string) ($function['arguments'] ?? '{}'));
        $tool_started_at = microtime(true);

        if (ProposalAbilities::requires_session_id($tool_name ?? '')) {
            $input['session_id'] = $session_id;
        }

        if ('awpt/finalize-proposal-review' === $tool_name) {
            $candidate_id = (int) ($turn_context['proposal_review_action_id'] ?? 0);
            $input['action_id'] = $candidate_id;
            $input['review_token'] = $candidate_id > 0;
        }

        // Pattern prep abilities are readonly but mint session-bound receipts.
        if (in_array($tool_name, ['awpt/prepare-pattern-change', 'awpt/prepare-pattern-draft'], true)) {
            $input['session_id'] = $session_id;
        }

        if ('awpt/search-knowledge' === $tool_name) {
            $input['session_id'] = $session_id;
            $input['seen_chunk_ids'] = array_values(array_keys($this->knowledge_chunks[$session_id] ?? []));
            $input['seen_query_fingerprints'] = array_values(array_keys($this->knowledge_queries[$session_id] ?? []));
            $input['seen_queries'] = $this->knowledge_query_texts[$session_id] ?? [];
        }

        if ('awpt/read-proposal' === $tool_name && (int) ($input['action_id'] ?? 0) <= 0) {
            $action_id = new ActionRepository()->resolve_revisable_new_post_id($session_id);

            if ($action_id > 0) {
                $input['action_id'] = $action_id;
            }
        }

        if (in_array(
            $tool_name,
            [
                'awpt/propose-new-post',
                'awpt/propose-content-update',
                'awpt/propose-block-attrs-update',
                'awpt/propose-block-batch-update',
                'awpt/propose-block-insert',
                'awpt/propose-block-remove',
                'awpt/propose-pattern-insert',
                'awpt/propose-pattern-replace',
                'awpt/propose-patterned-post',
            ],
            true,
        )) {
            $input = ArrayKey::string_map(new ProposalRequestContext()->enrich(
                $session_id,
                $input,
                $turn_context,
                $tool_name,
            ));
            $input = ArrayKey::string_map(new ImproveActProposeHydrator()->hydrate($session_id, $input, $tool_name));
        }

        if ('awpt/propose-patterned-post' === $tool_name) {
            $preparation = ArrayKey::as_map($turn_context['pattern_preparation'] ?? null);
            $pattern = ArrayKey::as_map($preparation['pattern'] ?? null);
            $prepared_names = ArrayKey::list_of_strings($pattern['pattern_names'] ?? null);

            if ([] !== $prepared_names) {
                // Preparation is trusted runtime evidence. The provider fills
                // slots; it cannot silently replace the verified composition.
                $input['pattern_names'] = $prepared_names;
                $input['pattern_name'] = $prepared_names[0];
            }
        }

        $execution_input = null === $tool_name ? $input : $tool_registry->native_input($tool_name, $input);
        $offered = is_array($turn_context['offered_tool_names'] ?? null)
            ? array_values(array_filter($turn_context['offered_tool_names'], 'is_string'))
            : null;
        if (is_wp_error($execution_input)) {
            $status = 'failed';
            $output = [
                'error' => $execution_input->get_error_message(),
                'error_code' => $execution_input->get_error_code(),
                'error_data' => $execution_input->get_error_data(),
            ];
        } else {
            [$status, $output] = $this->run_safe_tool(
                $tool_name,
                $function_name,
                $execution_input,
                $tool_registry,
                $offered,
            );
        }

        if ('success' === $status && 'awpt/read-pattern' === $tool_name && is_array($output)) {
            $pattern_name = (string) ($output['name'] ?? $input['name'] ?? '');

            if ('' !== $pattern_name) {
                $this->read_patterns[$session_id][$pattern_name] = true;
            }
        }

        if ('success' === $status && 'awpt/search-knowledge' === $tool_name && is_array($output)) {
            $items = is_array($output['items'] ?? null) ? $output['items'] : [];
            $this->seed_knowledge_chunks($session_id, $items);
            $fingerprint = (string) ($output['query_fingerprint'] ?? '');

            if ('' !== $fingerprint) {
                $this->knowledge_queries[$session_id][$fingerprint] = true;
            }

            $query = trim((string) ($output['query'] ?? $input['query'] ?? ''));

            if ('' !== $query) {
                $this->knowledge_query_texts[$session_id][] = $query;
            }
        }
        $tool = $tool_name ?? $function_name;
        $truncator = new ToolResultTruncator();
        $storage_output = $truncator->for_storage($tool, $output);
        // Failed calls: CLI-style stderr for the next round (fix / retry_with / use).
        // Keep full nested error_data in storage for the Tools UI.
        $provider_output = in_array($status, ['failed', 'rejected'], true) && is_array($output)
            ? FailedToolFeedback::for_provider($tool, ArrayKey::string_map($output))
            : $truncator->for_provider($tool, $output);
        if (is_array($provider_output['retry_with'] ?? null)) {
            $provider_output['retry_with'] = $tool_registry->provider_input(
                $tool,
                ArrayKey::as_map($provider_output['retry_with']),
            );
        }
        $visual_output = is_array($output) ? ArrayKey::string_map($output) : [];
        $visual_message =
            'success' === $status && [] !== $visual_output
                ? (
                    'awpt/inspect-rendered-element' === $tool
                        ? new RenderedInspectionVisualEvidence()->build($visual_output)
                        : new MediaLibraryVisualEvidence()->build($tool, $input, $visual_output)
                )
                : null;
        $tool_call = [
            'tool' => $tool,
            'input' => is_wp_error($execution_input) ? $input : $execution_input,
            'output' => $storage_output,
            'status' => $status,
            'provider_call_id' => $provider_call_id,
        ];

        if ('' === $provider_call_id) {
            $provider_call_id = 'awpt_local_' . wp_generate_password(8, false);
            $tool_call['provider_call_id'] = $provider_call_id;
        }

        AiLogger::log_tool_execute([
            'session_id' => $session_id,
            'turn_id' => sanitize_key((string) ($turn_context['turn_id'] ?? '')),
            'tool_name' => $tool,
            'input' => is_wp_error($execution_input)
                ? $input
                : (is_array($execution_input) ? $execution_input : ['value' => $execution_input]),
            'status' => $status,
            'output' => $storage_output,
            'started_at' => $tool_started_at,
            'meta' => [
                'provider_call_id' => $provider_call_id,
                'function_name' => $function_name,
            ],
        ]);

        // Available to mid-turn gates before AgentRuntime persists tool_calls.
        TurnToolEvidence::record($session_id, $tool_call);

        return [
            'tool_call' => $tool_call,
            'message' => [
                'role' => 'tool',
                'tool_call_id' => $provider_call_id,
                'content' => $this->encoded_tool_output($provider_output),
            ],
            'visual_message' => $visual_message,
        ];
    }

    /**
     * Run a safe tool or return a rejection result.
     *
     * @param string|null          $tool_name Ability name.
     * @param string               $function_name Provider function name.
     * @param mixed                $input Tool input.
     * @param ToolRegistry         $tool_registry Tool registry.
     * @param list<string>|null    $offered_tools Tools declared to the provider for this request.
     * @return array{0: string, 1: mixed}
     */
    private function run_safe_tool(
        ?string $tool_name,
        string $function_name,
        mixed $input,
        ToolRegistry $tool_registry,
        ?array $offered_tools = null,
    ): array {
        if (
            null === $tool_name
            || !$tool_registry->can_auto_execute($tool_name)
            || null !== $offered_tools && !in_array($tool_name, $offered_tools, true)
        ) {
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
            : (
                is_array($input)
                    ? new Adapter()->execute_tool($tool_name, $input)
                    : new \WP_Error('awpt_invalid_mcp_input', __(
                        'MCP tool input must be an object.',
                        'agent-wordpress-terminal',
                    ))
            );

        if (is_wp_error($result)) {
            $attribution = new \AWPT\Support\Diagnostics\ErrorPathAttributor()->from_text($result->get_error_message());
            $failed = [
                'error' => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
                'attribution' => $attribution,
            ];
            $error_data = ArrayKey::as_map_or_null($result->get_error_data()) ?? [];

            if (ProposalAbilities::is_proposal($tool_name)) {
                $constraints = ProposalFailureNormalizer::normalize(
                    (string) $result->get_error_code(),
                    $error_data,
                    $result->get_error_message(),
                );

                if ([] !== $constraints) {
                    $error_data['constraints'] = $constraints;
                }
            }

            if ([] !== $error_data) {
                $failed['error_data'] = $error_data;
            }

            return ['failed', $failed];
        }

        return ['success', $result];
    }

    /**
     * Decode provider tool call arguments.
     *
     * @return array<string, mixed>
     */
    private function decode_tool_arguments(string $arguments): array {
        return ArrayKey::as_map(json_decode($arguments, true));
    }

    private function encoded_tool_output(mixed $output): string {
        $encoded = wp_json_encode($output);

        return is_string($encoded) ? $encoded : 'null';
    }
}
