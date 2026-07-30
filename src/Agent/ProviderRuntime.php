<?php

/**
 * Provider-backed agent loop.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ActionRepository;
use AWPT\Database\IncidentRepository;
use AWPT\Database\MessageRepository;
use AWPT\Database\ProviderCallRepository;
use AWPT\Database\SessionRepository;
use AWPT\Knowledge\KnowledgeQueryNovelty;
use AWPT\Knowledge\KnowledgeSearchCache;
use AWPT\Knowledge\SessionKnowledgeEvidence;
use AWPT\Support\ArrayKey;
use AWPT\Support\ProposalAbilities;
use AWPT\Support\SiteDesignContext;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs natural language messages through the configured provider and tool loop.
 */
final class ProviderRuntime {
    /** Includes the initial response and every response after a tool result. */
    private const MAX_PROVIDER_COMPLETIONS = 6;

    /** Extra headroom for pattern-led page composition turns. */
    private const CONTENT_MAX_PROVIDER_COMPLETIONS = 8;

    /** Explore hops before forced compose on content turns (after initial complete). */
    private const MAX_EXPLORE_HOPS = 3;

    /** Render/review cycles before handing the latest verified proposal to the admin. */
    private const MAX_VISUAL_VERIFICATION_ROUNDS = 2;

    /**
     * Ordinary edit and analysis turns still need enough time to read site
     * evidence and make a final provider call. Sixty seconds routinely left the
     * evidence-refinement request with only a few seconds.
     */
    private const TURN_WALL_SECONDS = 120;

    private const CONTENT_TURN_WALL_SECONDS = 240;

    /**
     * Floor for follow-up provider HTTP calls after tools have run. Scheduling a
     * 5s completion after a long evidence loop almost always fails with cURL 28
     * and wastes the last hop; skip the call instead and keep the evidence.
     */
    private const MIN_USEFUL_REQUEST_SECONDS = 25;

    private const COMPOSITION_MAX_COMPLETION_TOKENS = 16_000;

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

    private VisionEvidencePreprocessor $vision_evidence;

    public function __construct(
        ?ProviderFactory $provider_factory = null,
        ?ProviderMessageBuilder $message_builder = null,
        ?ToolRegistry $tool_registry = null,
        ?VisionEvidencePreprocessor $vision_evidence = null,
    ) {
        $this->provider_factory = $provider_factory ?? new ProviderFactory();
        $this->message_builder = $message_builder ?? new ProviderMessageBuilder();
        $this->tool_registry = $tool_registry ?? new ToolRegistry();
        $this->tool_executor = new ProviderToolCallExecutor();
        $this->result_formatter = new ToolResultFormatter();
        $this->vision_evidence = $vision_evidence ?? new VisionEvidencePreprocessor();
    }

    /**
     * Get a provider-backed response.
     *
     * @param int $session_id Session ID.
     * @return array<string, mixed>
     */
    public function respond(int $session_id, array $turn_context = []): array {
        $provider = $this->provider_factory->make();
        $message = new MessageRepository()->latest_user_message($session_id);
        $budget = new GenerationBudget();
        $budget_context = $this->budget_context($session_id, $message);
        $profile = $this->turn_profile($session_id, $message, $budget_context);
        $knowledge_context = $profile->auto_retrieve_knowledge
            ? $this->knowledge_context($session_id, $message)
            : $this->empty_knowledge_context($message);
        $this->tool_executor->seed_knowledge_context($session_id, $knowledge_context);
        $messages = $this->message_builder->build($session_id, $knowledge_context, $profile);
        $messages = $this->add_attachment_evidence($messages, $turn_context['attachments'] ?? []);
        $budget_tokens = $budget->for_message($message, 0, $budget_context);
        $is_content_turn = $profile->content_turn;
        $is_content_edit_turn = $profile->content_edit_turn;
        $is_extended_turn = $is_content_turn || $is_content_edit_turn;
        $turn_wall_seconds = $is_extended_turn ? self::CONTENT_TURN_WALL_SECONDS : self::TURN_WALL_SECONDS;
        $started_at = microtime(true);
        $turn_id = (string) ($turn_context['turn_id'] ?? '');
        $uses_phases = $profile->uses_explore_compose_phases();
        $provider_tools = $uses_phases
            ? $this->tool_registry->get_exploration_tools($profile->explore_allowlist())
            : $this->tool_registry->get_chat_completion_tools_for_profile($profile);
        $vision = $this->vision_evidence->prepare($messages, $provider, $session_id, $turn_id);
        $messages = $vision['messages'];
        $this->record_vision_calls($session_id, $vision['calls']);
        $remaining = $turn_wall_seconds - (int) ceil(microtime(true) - $started_at);
        $turn_phase = $uses_phases ? 'explore' : 'direct';
        new ChatProgress()->update($session_id, $turn_id, [
            'phase' => $uses_phases ? 'exploring' : 'planning',
            'label' => $uses_phases
                ? __('Exploring site evidence', 'agent-wordpress-terminal')
                : __('Planning response', 'agent-wordpress-terminal'),
            'detail' => sprintf(__('Contacting %s…', 'agent-wordpress-terminal'), $provider->get_name()),
            'diagnostics' => array_merge([
                'provider' => $provider->get_name(),
                'mode' => $uses_phases ? 'explore' : 'planning',
                'turn_phase' => $turn_phase,
                'completion_budget' => $budget_tokens,
                'request_timeout_seconds' => min(120, max(5, $remaining)),
                'content_turn' => $is_content_turn,
                'content_edit_turn' => $is_content_edit_turn,
                'tools_offered' => count($provider_tools),
            ], $profile->diagnostics()),
        ]);
        $result = $provider->complete($messages, $provider_tools, [
            'session_id' => $session_id,
            'max_completion_tokens' => $budget_tokens,
            'timeout' => min(120, max(5, $remaining)),
        ]);
        $this->record_provider_call($session_id, [
            'provider' => $provider->get_name(),
            'tool_round' => 0,
            'budget' => $budget_tokens,
            'started_at' => $started_at,
            'result' => $result,
            'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
        ]);
        $notice = $this->vision_evidence->notice();

        if (is_wp_error($result)) {
            $failover = $this->maybe_failover($provider, $result, $messages, $provider_tools);

            if (null !== $failover) {
                [$provider, $result, $failover_notice] = $failover;
                $notice = trim($notice . "\n\n" . $failover_notice);
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

        $turn_context['progress_phase'] = $uses_phases ? 'exploring' : 'tools';
        $loop_result = $this->run_tool_loop($session_id, $provider, $messages, $result, [
            'turn_started_at' => $started_at,
            'turn_wall_seconds' => $turn_wall_seconds,
            'turn_context' => $turn_context,
            'budget_context' => $budget_context,
            'is_content_turn' => $is_content_turn,
            'is_content_edit_turn' => $is_content_edit_turn,
            'turn_profile' => $profile,
            'uses_explore_compose' => $uses_phases,
            'offered_tool_names' => $this->tool_registry->names_from_declarations($provider_tools),
        ]);

        $content = trim($loop_result['content']);
        $tool_calls = is_array($loop_result['tool_calls'] ?? null) ? $loop_result['tool_calls'] : [];
        $knowledge_trace = $this->knowledge_trace($message, $knowledge_context);

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
        $vision_notice = $this->vision_evidence->notice();

        if ('' !== $vision_notice && !str_contains($notice, $vision_notice)) {
            $notice = trim($notice . "\n\n" . $vision_notice);
        }

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
        $explore_hops = 0;
        $proposal_failures = 0;
        $corrective_replan_sent = false;
        $recovery_stall_nudge_sent = false;
        $discovery_nudge_sent = false;
        $compose_only = false;
        $compose_compacted = false;
        $last_discovery = ['compose' => false, 'reason' => '', 'coverage' => []];
        $user_message = new MessageRepository()->latest_user_message($session_id);
        $turn_started_at = is_float($options['turn_started_at'] ?? null)
            ? $options['turn_started_at']
            : microtime(true);
        $turn_context = is_array($options['turn_context'] ?? null) ? $options['turn_context'] : [];
        $budget_context = $this->normalize_budget_context($options['budget_context'] ?? null);
        $is_content_turn = ArrayKey::rest_bool($options['is_content_turn'] ?? false);
        $is_content_edit_turn = ArrayKey::rest_bool($options['is_content_edit_turn'] ?? false);
        $turn_profile = $options['turn_profile'] ?? null;
        $turn_profile = $turn_profile instanceof TurnProfile ? $turn_profile : null;
        $uses_explore_compose =
            ArrayKey::rest_bool($options['uses_explore_compose'] ?? false)
            || ($turn_profile?->uses_explore_compose_phases() ?? false);
        $max_provider_completions = $is_content_turn || $is_content_edit_turn
            ? self::CONTENT_MAX_PROVIDER_COMPLETIONS
            : self::MAX_PROVIDER_COMPLETIONS;
        $formatted_after_success = false;
        $turn_phase = $uses_explore_compose ? 'explore' : 'direct';
        $visual_verification_rounds = 0;
        $offered_tool_names = array_key_exists('offered_tool_names', $options)
            ? array_values(array_filter(
                is_array($options['offered_tool_names']) ? $options['offered_tool_names'] : [],
                'is_string',
            ))
            : null;
        $activated_tool_names = [];

        while ($provider_completions <= $max_provider_completions) {
            $turn_context['progress_phase'] = $this->phase_choice(
                $compose_only,
                $uses_explore_compose,
                'composing',
                'exploring',
                'tools',
            );
            if (null !== $offered_tool_names) {
                $turn_context['offered_tool_names'] = $offered_tool_names;
            } else {
                unset($turn_context['offered_tool_names']);
            }
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

            if ($uses_explore_compose && !$compose_only) {
                ++$explore_hops;
            }

            $tool_calls = [...$tool_calls, ...$execution['tool_calls']];
            $actions = [...$actions, ...$this->actions_from_tool_calls($execution['tool_calls'])];
            $messages[] = $this->tool_executor->assistant_tool_call_message($result);
            $messages = array_merge($messages, $execution['messages']);

            foreach ($execution['tool_calls'] as $call) {
                if ('success' !== (string) ($call['status'] ?? '') || 'awpt/find-abilities' !== $call['tool']) {
                    continue;
                }

                $output = is_array($call['output'] ?? null) ? $call['output'] : [];
                $activated = is_array($output['activated'] ?? null) ? $output['activated'] : [];

                foreach (array_filter($activated, 'is_string') as $ability_name) {
                    if ($tool_registry->can_auto_execute($ability_name)) {
                        $activated_tool_names[] = $ability_name;
                    }
                }

                $activated_tool_names = array_values(array_unique($activated_tool_names));
            }

            foreach ($execution['tool_calls'] as $call) {
                $tool_name = (string) ($call['tool'] ?? '');

                if (ToolRegistry::is_proposal_ability($tool_name) && 'success' !== (string) ($call['status'] ?? '')) {
                    ++$proposal_failures;
                }
            }

            if ($this->has_successful_proposal($execution['tool_calls'])) {
                new ChatProgress()->update($session_id, (string) ($turn_context['turn_id'] ?? ''), [
                    'phase' => 'preview',
                    'label' => __('Preparing preview', 'agent-wordpress-terminal'),
                    'detail' => __('The staged proposal passed validation.', 'agent-wordpress-terminal'),
                    'diagnostics' => [
                        'turn_phase' => 'compose',
                        'explore_hops' => $explore_hops,
                        'compose_compacted' => $compose_compacted,
                        'parallel_batch_size' => (int) ($execution['parallel_batch_size'] ?? 0),
                    ],
                ]);
                $proposal_calls = array_values(array_filter(
                    $tool_calls,
                    static fn(array $call): bool => 'success' === (string) ($call['status'] ?? '')
                    && ToolRegistry::is_proposal_ability((string) ($call['tool'] ?? '')),
                ));
                $verification = $visual_verification_rounds < self::MAX_VISUAL_VERIFICATION_ROUNDS
                    ? new ProposalPreviewVerifier()->verify($proposal_calls)
                    : null;

                if (null !== $verification) {
                    ++$visual_verification_rounds;
                    $tool_calls[] = $verification['tool_call'];
                    $messages[] = $verification['message'];
                    $remaining =
                        (int) ($options['turn_wall_seconds'] ?? self::TURN_WALL_SECONDS)
                        - (int) ceil(microtime(true) - $turn_started_at);

                    if (
                        $remaining >= self::MIN_USEFUL_REQUEST_SECONDS
                        && $provider_completions < $max_provider_completions
                    ) {
                        new ChatProgress()->update($session_id, (string) ($turn_context['turn_id'] ?? ''), [
                            'phase' => 'verifying',
                            'label' => __('Reviewing rendered preview', 'agent-wordpress-terminal'),
                            'detail' => __(
                                'The model is checking screenshot and computed-style evidence before presenting the proposal.',
                                'agent-wordpress-terminal',
                            ),
                            'diagnostics' => [
                                'visual_verification_round' => $visual_verification_rounds,
                                'rendered' => true === ($verification['tool_call']['output']['rendered'] ?? false),
                            ],
                        ]);
                        $review_tools = $tool_registry->get_chat_completion_tools(
                            $turn_profile?->compose_allowlist() ?? ProposalAbilities::names(),
                        );
                        $review_started_at = microtime(true);
                        $review = $provider->complete($messages, $review_tools, [
                            'session_id' => $session_id,
                            'max_completion_tokens' => self::COMPOSITION_MAX_COMPLETION_TOKENS,
                            // The agent may accept the verified proposal with prose
                            // or choose any targeted proposal operation to revise it.
                            'tool_choice' => 'auto',
                            'timeout' => min(90, max(self::MIN_USEFUL_REQUEST_SECONDS, $remaining)),
                        ]);
                        ++$provider_completions;
                        $this->record_provider_call($session_id, [
                            'provider' => $provider->get_name(),
                            'tool_round' => count($tool_calls),
                            'budget' => self::COMPOSITION_MAX_COMPLETION_TOKENS,
                            'started_at' => $review_started_at,
                            'result' => $review,
                            'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
                        ]);

                        if (is_array($review)) {
                            $result = $review;
                            $review_content = trim((string) ($review['content'] ?? ''));

                            if ($this->has_tool_calls($review)) {
                                $offered_tool_names = $tool_registry->names_from_declarations($review_tools);
                                $content = $review_content;
                                $compose_only = true;
                                $turn_phase = 'compose';
                                continue;
                            }

                            $content = $this->result_formatter->format_for_transcript($proposal_calls, $review_content);
                            $formatted_after_success = true;
                            break;
                        }
                    }
                }

                $content = $this->result_formatter->format_for_transcript($proposal_calls, $content);
                $formatted_after_success = true;
                break;
            }

            if ($proposal_failures >= 2 || $provider_completions >= $max_provider_completions) {
                if ($proposal_failures >= 2) {
                    $content = __(
                        'I could not stage the proposal after one corrected attempt. The validation failures are preserved below so the next attempt can use verified site evidence.',
                        'agent-wordpress-terminal',
                    );
                }
                break;
            }

            if ($proposal_failures > 0 && !$corrective_replan_sent) {
                $compose_only = false;
                $compose_compacted = false;
                $turn_phase = $uses_explore_compose ? 'explore' : 'direct';
                $messages[] = [
                    'role' => 'system',
                    'content' => 'The staging attempt failed validation. Reconsider the approach and make at most one corrected staging attempt. Read the complete structured error_data: address every listed issue, use exact available identifiers, and call the recommended read tools when evidence is missing. Reuse pattern markup already returned in this turn instead of re-reading the same patterns. You retain recovery tools. Do not repeat unchanged arguments or ask the user to choose routine creative details you can decide.',
                ];
                $corrective_replan_sent = true;
            }

            $discovery_decision = new DiscoveryPolicy()->decide(
                $user_message,
                $tool_calls,
                $execution['tool_calls'],
                (int) floor(microtime(true) - $turn_started_at),
                $is_content_turn || $is_content_edit_turn,
            );
            $last_discovery = $discovery_decision;

            $should_enter_compose =
                $uses_explore_compose
                && 0 === $proposal_failures
                && !$compose_only
                && ($discovery_decision['compose'] || $explore_hops >= self::MAX_EXPLORE_HOPS);

            if ($should_enter_compose) {
                $reason = $discovery_decision['compose']
                    ? $discovery_decision['reason']
                    : 'The explore hop budget is complete; stage from verified evidence now.';
                $coverage = $discovery_decision['coverage'];
                $messages = new EvidencePackBuilder()->provider_messages($messages, $tool_calls, $user_message, [
                    'coverage' => $coverage,
                    'reason' => $reason,
                    'mode' => 'compose',
                ]);
                $compose_only = true;
                $compose_compacted = true;
                $discovery_nudge_sent = true;
                $turn_phase = 'compose';
            } elseif (
                !$uses_explore_compose
                && 0 === $proposal_failures
                && $discovery_decision['compose']
                && !$discovery_nudge_sent
            ) {
                $messages[] = [
                    'role' => 'system',
                    'content' => sprintf(
                        'Discovery is complete: %s Coverage: %s. Compose and stage the requested proposal now using the verified evidence. Do not perform more discovery.',
                        $discovery_decision['reason'],
                        implode(', ', $discovery_decision['coverage']),
                    ),
                ];
                $discovery_nudge_sent = true;
                $compose_only = true;
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
                'budget_context' => $budget_context,
                'is_content_turn' => $is_content_turn,
                'compose_only' => $compose_only,
                'finalization_retry' => false,
                'turn_profile' => $turn_profile,
                'turn_phase' => $turn_phase,
                'explore_hops' => $explore_hops,
                'compose_compacted' => $compose_compacted,
                'evidence_pack_chars' => $compose_compacted
                    ? new EvidencePackBuilder()->encoded_size(
                        $tool_calls,
                        is_array($last_discovery['coverage'] ?? null) ? $last_discovery['coverage'] : [],
                        (string) ($last_discovery['reason'] ?? ''),
                    )
                    : 0,
                'activated_tool_names' => $activated_tool_names,
            ];
            new ChatProgress()->update($session_id, (string) ($turn_context['turn_id'] ?? ''), [
                'phase' => $this->phase_choice(
                    $compose_only,
                    $uses_explore_compose,
                    'composing',
                    'exploring',
                    'composing',
                ),
                'label' => $this->phase_choice(
                    $compose_only,
                    $uses_explore_compose,
                    __('Staging proposal', 'agent-wordpress-terminal'),
                    __('Exploring site evidence', 'agent-wordpress-terminal'),
                    __('Composing response', 'agent-wordpress-terminal'),
                ),
                'detail' => sprintf(
                    __('Using evidence from %d completed tool calls…', 'agent-wordpress-terminal'),
                    count($tool_calls),
                ),
                'diagnostics' => [
                    'turn_phase' => $turn_phase,
                    'explore_hops' => $explore_hops,
                    'compose_compacted' => $compose_compacted,
                    'parallel_batch_size' => (int) ($execution['parallel_batch_size'] ?? 0),
                ],
            ]);
            $follow_up = $this->follow_up_round($provider, $tool_registry, $state);
            ++$provider_completions;

            $content = $follow_up['content'];
            $result = $follow_up['result'];
            $offered_tool_names = is_array($follow_up['offered_tool_names'] ?? null)
                ? array_values(array_filter($follow_up['offered_tool_names'], 'is_string'))
                : [];

            if ($follow_up['continue']) {
                continue;
            }

            if (
                $proposal_failures > 0
                && !$recovery_stall_nudge_sent
                && $provider_completions < $max_provider_completions
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
                    'budget_context' => $budget_context,
                    'turn_wall_seconds' => (int) ($options['turn_wall_seconds'] ?? self::TURN_WALL_SECONDS),
                    'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
                    'is_content_turn' => $is_content_turn,
                    'compose_only' => false,
                    'finalization_retry' => false,
                    'turn_profile' => $turn_profile,
                    'turn_phase' => 'explore',
                    'explore_hops' => $explore_hops,
                    'compose_compacted' => false,
                    'activated_tool_names' => $activated_tool_names,
                ];
                $follow_up = $this->follow_up_round($provider, $tool_registry, $state);
                ++$provider_completions;
                $content = $follow_up['content'];
                $result = $follow_up['result'];
                $offered_tool_names = is_array($follow_up['offered_tool_names'] ?? null)
                    ? array_values(array_filter($follow_up['offered_tool_names'], 'is_string'))
                    : [];

                if ($follow_up['continue']) {
                    continue;
                }
            }

            break;
        }

        if (!$formatted_after_success && [] !== $tool_calls && '' === trim($content)) {
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
     * @param array<string, mixed> $state
     * @return array{content: string, result: array<string, mixed>, continue: bool, offered_tool_names: list<string>}
     */
    private function follow_up_round(ProviderInterface $provider, ToolRegistry $tool_registry, array $state): array {
        $session_id = (int) ($state['session_id'] ?? 0);
        /** @var array<int, array<string, mixed>> $tool_calls */
        $tool_calls = array_values(array_filter(
            is_array($state['tool_calls'] ?? null) ? $state['tool_calls'] : [],
            static fn(mixed $call): bool => is_array($call),
        ));
        /** @var array<int, array<string, mixed>> $messages */
        $messages = array_values(array_filter(
            is_array($state['messages'] ?? null) ? $state['messages'] : [],
            static fn(mixed $message): bool => is_array($message),
        ));
        /** @var array<string, mixed> $prior_result */
        $prior_result = [];

        if (is_array($state['result'] ?? null)) {
            foreach ($state['result'] as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $prior_result[$key] = $value;
            }
        }

        $content = (string) ($state['content'] ?? '');
        $turn_started_at = $this->float_value($state['turn_started_at'] ?? null, microtime(true));
        $turn_wall_seconds = (int) ($state['turn_wall_seconds'] ?? self::TURN_WALL_SECONDS);
        $turn_id = (string) ($state['turn_id'] ?? '');
        $message = new MessageRepository()->latest_user_message($session_id);
        $budget_context = $this->normalize_budget_context($state['budget_context'] ?? null);
        $budget_tokens = new GenerationBudget()->for_message($message, count($tool_calls), $budget_context);
        $started_at = microtime(true);
        $compose_only = ArrayKey::rest_bool($state['compose_only'] ?? false);
        $completion_budget = $compose_only ? self::COMPOSITION_MAX_COMPLETION_TOKENS : $budget_tokens;
        $vision = $this->vision_evidence->prepare($messages, $provider, $session_id, $turn_id);
        $messages = $vision['messages'];
        $this->record_vision_calls($session_id, $vision['calls']);
        $remaining = $turn_wall_seconds - (int) ceil(microtime(true) - $turn_started_at);

        if ($remaining < self::MIN_USEFUL_REQUEST_SECONDS) {
            return [
                'content' => $this->result_formatter->format_incomplete_turn($tool_calls),
                'result' => $prior_result,
                'continue' => false,
                'offered_tool_names' => [],
            ];
        }

        $turn_profile = $state['turn_profile'] ?? null;
        $turn_profile = $turn_profile instanceof TurnProfile ? $turn_profile : null;
        $uses_phases = $turn_profile?->uses_explore_compose_phases() ?? false;
        $activated = array_values(array_filter(
            is_array($state['activated_tool_names'] ?? null) ? $state['activated_tool_names'] : [],
            'is_string',
        ));

        if ($compose_only) {
            $compose_abilities = $turn_profile?->compose_allowlist() ?? ['awpt/propose-new-post'];
            $provider_tools = $tool_registry->get_chat_completion_tools($compose_abilities);
            $proposal_function = [] !== $provider_tools ? 'required' : null;
        } elseif ($uses_phases) {
            $provider_tools = $tool_registry->get_exploration_tools([
                ...($turn_profile?->explore_allowlist() ?? []),
                ...$activated,
            ]);
            $proposal_function = null;
        } else {
            $provider_tools = null !== $turn_profile
                ? $tool_registry->get_chat_completion_tools_for_allowlist([
                    ...$turn_profile->tool_allowlist(),
                    ...$activated,
                ])
                : $tool_registry->get_chat_completion_tools($activated);
            $proposal_function = null;
        }

        // Require an approval-gated action without forcing which action. The
        // evidence pack should drive that decision.
        $tool_choice = 'required' === $proposal_function ? 'required' : 'auto';
        $offered_tool_names = $tool_registry->names_from_declarations($provider_tools);
        $request_timeout = min(120, max(self::MIN_USEFUL_REQUEST_SECONDS, $remaining));
        $turn_phase = is_string($state['turn_phase'] ?? null)
            ? $state['turn_phase']
            : $this->phase_choice($compose_only, $uses_phases, 'compose', 'explore', 'direct');
        new ChatProgress()->update($session_id, $turn_id, [
            'phase' => $this->phase_choice($compose_only, $uses_phases, 'finalizing', 'exploring', 'researching'),
            'label' => $this->phase_choice(
                $compose_only,
                $uses_phases,
                __('Staging proposal', 'agent-wordpress-terminal'),
                __('Exploring site evidence', 'agent-wordpress-terminal'),
                __('Refining evidence', 'agent-wordpress-terminal'),
            ),
            'detail' => $compose_only
                ? sprintf(
                    __('Proposal-only generation using %d verified tool result(s).', 'agent-wordpress-terminal'),
                    count($tool_calls),
                )
                : sprintf(
                    __(
                        'The model may refine evidence or act; %d tool result(s) are available.',
                        'agent-wordpress-terminal',
                    ),
                    count($tool_calls),
                ),
            'diagnostics' => [
                'provider' => $provider->get_name(),
                'mode' => $this->phase_choice($compose_only, $uses_phases, 'proposal_only', 'explore', 'discovery'),
                'turn_phase' => $turn_phase,
                'explore_hops' => (int) ($state['explore_hops'] ?? 0),
                'compose_compacted' => ArrayKey::rest_bool($state['compose_compacted'] ?? false),
                'evidence_pack_chars' => (int) ($state['evidence_pack_chars'] ?? 0),
                'tool_count' => count($tool_calls),
                'tools_offered' => count($provider_tools),
                'completion_budget' => $completion_budget,
                'request_timeout_seconds' => $request_timeout,
                'proposal_only' => $compose_only,
            ],
        ]);
        $follow_up = $provider->complete($messages, $provider_tools, [
            'session_id' => $session_id,
            'max_completion_tokens' => $completion_budget,
            'tool_choice' => $tool_choice,
            'timeout' => $request_timeout,
        ]);
        $retried_finalization = false;
        $this->record_provider_call($session_id, [
            'provider' => $provider->get_name(),
            'tool_round' => count($tool_calls),
            'budget' => $completion_budget,
            'started_at' => $started_at,
            'result' => $follow_up,
            'turn_id' => $turn_id,
        ]);

        if (
            !is_array($follow_up)
            && $compose_only
            && !ArrayKey::rest_bool($state['finalization_retry'] ?? false)
            && $this->is_retryable_finalization_error($follow_up)
        ) {
            $retry_started_at = microtime(true);
            $retry_remaining = $turn_wall_seconds - (int) ceil($retry_started_at - $turn_started_at);

            if ($retry_remaining >= self::MIN_USEFUL_REQUEST_SECONDS) {
                $retried_finalization = true;
                new ChatProgress()->update($session_id, $turn_id, [
                    'phase' => 'retrying',
                    'label' => __('Retrying proposal finalization', 'agent-wordpress-terminal'),
                    'detail' => __(
                        'Using compact verified evidence with discovery disabled.',
                        'agent-wordpress-terminal',
                    ),
                    'diagnostics' => [
                        'provider' => $provider->get_name(),
                        'mode' => 'proposal_retry',
                        'tool_count' => count($tool_calls),
                        'completion_budget' => self::COMPOSITION_MAX_COMPLETION_TOKENS,
                        'request_timeout_seconds' => min(90, max(self::MIN_USEFUL_REQUEST_SECONDS, $retry_remaining)),
                        'proposal_only' => true,
                    ],
                ]);
                $follow_up = $provider->complete(
                    $this->compact_finalization_messages($messages, $tool_calls, $message),
                    $provider_tools,
                    [
                        'session_id' => $session_id,
                        'max_completion_tokens' => self::COMPOSITION_MAX_COMPLETION_TOKENS,
                        'tool_choice' => $tool_choice,
                        'timeout' => min(90, max(self::MIN_USEFUL_REQUEST_SECONDS, $retry_remaining)),
                    ],
                );
                $this->record_provider_call($session_id, [
                    'provider' => $provider->get_name(),
                    'tool_round' => count($tool_calls),
                    'budget' => self::COMPOSITION_MAX_COMPLETION_TOKENS,
                    'started_at' => $retry_started_at,
                    'result' => $follow_up,
                    'turn_id' => $turn_id,
                ]);
            }
        }

        if (!is_array($follow_up)) {
            $failure = is_wp_error($follow_up)
                ? $this->result_formatter->format_incomplete_turn($tool_calls, $follow_up->get_error_message())
                : $content;

            return [
                // Finalization formats the tool results once. Returning an
                // already-formatted transcript here duplicated every read.
                'content' => $failure,
                'result' => $prior_result,
                'continue' => false,
                'offered_tool_names' => $offered_tool_names,
            ];
        }

        $result = $follow_up;
        $follow_up_content = trim((string) ($result['content'] ?? ''));

        if ($this->has_tool_calls($result)) {
            return [
                'content' => '' !== $follow_up_content ? $follow_up_content : $content,
                'result' => $result,
                'continue' => true,
                'offered_tool_names' => $offered_tool_names,
            ];
        }

        return [
            'content' => '' !== $follow_up_content
                ? $follow_up_content
                : $this->result_formatter->format_for_transcript($tool_calls, $content),
            'result' => $result,
            'continue' => false,
            'offered_tool_names' => $offered_tool_names,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages Provider messages.
     * @param array<int, array<string, mixed>> $tools Tools offered on the failed call.
     * @return array{0: ProviderInterface, 1: array<string, mixed>|\WP_Error, 2: string}|null
     */
    private function maybe_failover(
        ProviderInterface $provider,
        \WP_Error $error,
        array $messages,
        array $tools = [],
    ): ?array {
        if (
            !$provider instanceof WordPressAIClientProvider
            || self::NO_TEXT_GENERATION_ERROR_CODE !== $error->get_error_code()
        ) {
            return null;
        }

        $fallback = new OpenRouterProvider();
        $fallback_tools = [] !== $tools ? $tools : $this->tool_registry->get_chat_completion_tools();
        $fallback_result = $fallback->complete($messages, $fallback_tools);

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
    private function knowledge_trace(string $message, array $context): ?array {
        $message = trim($message);
        if ('' === $message) {
            return null;
        }

        $results = is_array($context['items'] ?? null) ? $context['items'] : [];
        $known = is_array($context['known_matches'] ?? null) ? $context['known_matches'] : [];

        if ([] === $results && [] === $known) {
            return null;
        }

        return [
            'tool' => 'awpt/knowledge-auto-retrieval',
            'input' => [
                'query' => (string) ($context['query'] ?? ''),
                'user_query' => $message,
                'limit' => 5,
            ],
            'output' => [
                'count' => count($results),
                'results' => $results,
                'known_matches' => $known,
                'novel_count' => (int) ($context['novel_count'] ?? count($results)),
                'reused_count' => (int) ($context['reused_count'] ?? count($known)),
                'exhausted' => true === ($context['exhausted'] ?? false),
                'query_fingerprint' => $context['query_fingerprint'],
            ],
            'status' => 'success',
        ];
    }

    /** @return array<string, mixed> */
    private function knowledge_context(int $session_id, string $message): array {
        $query = new SiteDesignContext()->enrich_retrieval_query(trim($message));
        $session_evidence = new SessionKnowledgeEvidence();
        $context = new KnowledgeSearchCache()->context($query, 5, $session_evidence->chunk_ids($session_id));
        $repeated =
            in_array($context['query_fingerprint'], $session_evidence->query_fingerprints($session_id), true)
            || new KnowledgeQueryNovelty()->repeats($query, $session_evidence->queries($session_id));

        if ($repeated) {
            $context['items'] = [];
            $context['novel_count'] = 0;
            $context['exhausted'] = true;
            $context['repeated_query'] = true;
        }

        return $context;
    }

    /** @return array<string, mixed> */
    private function empty_knowledge_context(string $message): array {
        $query = trim($message);

        return [
            'query' => $query,
            'query_fingerprint' => hash('sha256', mb_strtolower($query)),
            'items' => [],
            'known_matches' => [],
            'novel_count' => 0,
            'reused_count' => 0,
            'exhausted' => false,
            'skipped' => true,
        ];
    }

    /**
     * @param array{
     *     prior_user_messages: list<string>,
     *     has_open_new_post_proposal: bool
     * } $budget_context
     */
    private function turn_profile(int $session_id, string $message, array $budget_context): TurnProfile {
        $open_actions = new ActionRepository()->list_open_for_session($session_id);
        $incidents = new IncidentRepository()->list_open($session_id, 1);
        $session = new SessionRepository()->get_summary($session_id);

        return TurnProfile::from_message($message, $budget_context, [
            'has_open_proposals' => [] !== $open_actions,
            'has_open_incidents' => [] !== $incidents,
            'has_focus' => (int) ($session['focus_post_id'] ?? 0) > 0,
        ]);
    }

    private function is_retryable_finalization_error(mixed $result): bool {
        if (!is_wp_error($result)) {
            return false;
        }

        $text = mb_strtolower($result->get_error_code() . ' ' . $result->get_error_message());

        return (
            str_contains($text, 'timeout')
            || str_contains($text, 'timed out')
            || str_contains($text, 'curl error 28')
            || 'http_request_failed' === $result->get_error_code()
        );
    }

    /**
     * Rebuild a small, valid conversation for one proposal-only timeout retry.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tool_calls
     * @return array<int, array<string, mixed>>
     */
    private function compact_finalization_messages(array $messages, array $tool_calls, string $user_message): array {
        return new EvidencePackBuilder()->provider_messages($messages, $tool_calls, $user_message, [
            'mode' => 'retry',
        ]);
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
    private function has_successful_proposal(array $tool_calls): bool {
        foreach ($tool_calls as $tool_call) {
            if (
                ToolRegistry::is_proposal_ability((string) ($tool_call['tool'] ?? ''))
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
     * @param list<array<string, mixed>> $calls
     */
    private function record_vision_calls(int $session_id, array $calls): void {
        foreach ($calls as $call) {
            $this->record_provider_call($session_id, $call);
        }
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

        $evidence = new MediaLibraryVisualEvidence()->parts_for_composer_attachments($attachments);

        if ([] === $evidence) {
            return $messages;
        }

        for ($index = count($messages) - 1; $index >= 0; --$index) {
            if (
                'user' !== (string) ($messages[$index]['role'] ?? '')
                || !is_string($messages[$index]['content'] ?? null)
            ) {
                continue;
            }

            $messages[$index]['content'] = [
                ['type' => 'text', 'text' => $messages[$index]['content']],
                ...$evidence,
            ];
            break;
        }

        return $messages;
    }

    /**
     * @return array{
     *     prior_user_messages: list<string>,
     *     has_open_new_post_proposal: bool
     * }
     */
    private function budget_context(int $session_id, string $latest_user_message): array {
        unset($latest_user_message);

        $prior_user_messages = [];

        foreach (new MessageRepository()->session_messages($session_id, 16) as $row) {
            if ('user' !== ($row['role'] ?? '')) {
                continue;
            }

            $content = trim($row['content'] ?? '');

            if ('' === $content) {
                continue;
            }

            $prior_user_messages[] = $content;
        }

        return [
            'prior_user_messages' => $prior_user_messages,
            'has_open_new_post_proposal' => null !== new ActionRepository()->latest_open_new_post_for_session(
                $session_id,
            ),
        ];
    }

    /**
     * @return array{
     *     prior_user_messages: list<string>,
     *     has_open_new_post_proposal: bool
     * }
     */
    private function normalize_budget_context(mixed $context): array {
        if (!is_array($context)) {
            return [
                'prior_user_messages' => [],
                'has_open_new_post_proposal' => false,
            ];
        }

        $prior = [];

        foreach (ArrayKey::list_of_strings($context['prior_user_messages'] ?? null) as $message) {
            if ('' === trim($message)) {
                continue;
            }

            $prior[] = $message;
        }

        return [
            'prior_user_messages' => $prior,
            'has_open_new_post_proposal' => ArrayKey::rest_bool($context['has_open_new_post_proposal'] ?? false),
        ];
    }

    /**
     * Resolve a UI/diagnostic string for compose vs explore vs default.
     */
    private function phase_choice(
        bool $compose_only,
        bool $uses_explore,
        string $when_compose,
        string $when_explore,
        string $fallback,
    ): string {
        if ($compose_only) {
            return $when_compose;
        }

        if ($uses_explore) {
            return $when_explore;
        }

        return $fallback;
    }

    private function float_value(mixed $value, float $fallback): float {
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }
}
