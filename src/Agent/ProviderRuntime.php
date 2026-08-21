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
use AWPT\Database\SessionEventRepository;
use AWPT\Database\SessionRepository;
use AWPT\Knowledge\KnowledgeQueryNovelty;
use AWPT\Knowledge\KnowledgeSearchCache;
use AWPT\Knowledge\SessionKnowledgeEvidence;
use AWPT\Support\ArrayKey;
use AWPT\Support\ImprovePagePrompt;
use AWPT\Support\PageScale;
use AWPT\Support\ProposalAbilities;
use AWPT\Support\SiteDesignContext;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs natural language messages through the configured provider and tool loop.
 */
final class ProviderRuntime {
    /** Internal result envelope; never registered as a WordPress Ability. */
    private const IMPROVE_PLAN_FUNCTION = 'awpt_internal_submit_improve_plan';

    /** Effectively disables AWPT's transport/wall cutoff while developing. */
    private const DEVELOPMENT_REQUEST_TIMEOUT_SECONDS = 31_536_000;

    /** Includes the initial response and every response after a tool result. */
    private const MAX_PROVIDER_COMPLETIONS = 6;

    /** Extra headroom for pattern-led page composition turns. */
    private const CONTENT_MAX_PROVIDER_COMPLETIONS = 10;

    /** Initial staging plus two bounded validation-informed corrections. */
    private const MAX_PROPOSAL_FAILURES = 3;

    /** Explore hops before forced compose on content turns (after initial complete). */
    private const MAX_EXPLORE_HOPS = 3;

    /** Initial candidate plus bounded agent-authored corrections. */
    private const MAX_VISUAL_VERIFICATION_ROUNDS = 6;

    /**
     * Ordinary edit and analysis turns still need enough time to read site
     * evidence and make a final provider call. Sixty seconds routinely left the
     * evidence-refinement request with only a few seconds.
     */
    private const TURN_WALL_SECONDS = 120;

    private const CONTENT_TURN_WALL_SECONDS = 240;

    /**
     * Improve evaluate is discovery-only. Keep this long while we inspect
     * full evaluate I/O; a short per-request cap was cutting off live plan
     * writes (cURL 28 with a partial body).
     */
    private const IMPROVE_EVALUATE_WALL_SECONDS = 720;

    /**
     * Execute-plan may legitimately spend several minutes composing a large,
     * verified proposal and still need a correction. This is an overall circuit
     * breaker, not a per-phase allocation; completion and proposal-failure caps
     * remain the primary protection against self-calling loops.
     */
    private const IMPROVE_ACT_WALL_SECONDS = 720;

    /** Few provider hops: read structure → optional recommend → write plan. */
    private const IMPROVE_EVALUATE_MAX_COMPLETIONS = 4;

    /** Act includes candidate review and bounded evidence-informed revisions. */
    private const IMPROVE_ACT_MAX_COMPLETIONS = 12;

    /** Explore hops before forced compose on Execute-plan turns. */
    private const IMPROVE_ACT_MAX_EXPLORE_HOPS = 1;

    /** Raw from-scratch Gutenberg documents need more transport time than compact pattern edits. */
    private const RAW_COMPOSITION_TURN_WALL_SECONDS = 480;

    /**
     * Floor for follow-up provider HTTP calls after tools have run. Scheduling a
     * 5s completion after a long evidence loop almost always fails with cURL 28
     * and wastes the last hop; skip the call instead and keep the evidence.
     */
    private const MIN_USEFUL_REQUEST_SECONDS = 25;

    /**
     * A complete Gutenberg proposal normally fits below this ceiling. Keeping it
     * bounded also prevents a raw-markup finalization from monopolizing the
     * whole content-turn wall clock after discovery has already succeeded.
     */
    private const COMPOSITION_MAX_COMPLETION_TOKENS = 4_800;

    /** Existing pages may need to resend substantial preserved source copy in an adapted layout. */
    private const EXISTING_CONTENT_COMPOSITION_MAX_COMPLETION_TOKENS = 20_000;

    /** The raw escape hatch remains expressive; this is a provider response budget, not a stored-page limit. */
    private const RAW_COMPOSITION_MAX_COMPLETION_TOKENS = 20_000;

    /**
     * Ceiling shared with ChatCompletionsProvider. Evaluate finalize must not
     * invent a tighter cap — reasoning output can otherwise consume the result.
     */
    private const PROVIDER_MAX_COMPLETION_TOKENS = 32_000;

    /**
     * Let one productive existing-page composition use nearly all of the outer
     * safety ceiling. The runtime does not reserve rigid slices for later phases;
     * a correction simply receives whatever wall time remains.
     */
    private const EXISTING_COMPOSE_REQUEST_SECONDS = 450;

    /** Retry after a compose transport failure; keep long enough to see the real return. */
    private const EXISTING_COMPOSE_RETRY_SECONDS = 450;

    private const RAW_COMPOSE_REQUEST_SECONDS = 450;

    private const RAW_COMPOSE_RETRY_SECONDS = 300;

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
        \AWPT\Support\TurnToolEvidence::reset($session_id);
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
        $is_improve_evaluate = $profile->is_improve_evaluate();
        $is_improve_act = $profile->is_improve_act();
        $unbounded_agent_runtime = $this->unbounded_agent_runtime();
        $is_extended_turn = $is_content_turn || $is_content_edit_turn || [] !== $profile->compose_allowlist();
        $turn_wall_seconds = $unbounded_agent_runtime
            ? self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS
            : match (true) {
                $is_improve_evaluate => self::IMPROVE_EVALUATE_WALL_SECONDS,
                $is_improve_act => self::IMPROVE_ACT_WALL_SECONDS,
                $is_extended_turn => self::CONTENT_TURN_WALL_SECONDS,
                default => self::TURN_WALL_SECONDS,
            };
        $started_at = microtime(true);
        $turn_id = (string) ($turn_context['turn_id'] ?? '');
        $uses_phases = $profile->uses_explore_compose_phases();
        $has_open_new_post = $budget_context['has_open_new_post_proposal'] ?? false;
        // Creation language can also match the broad edit classifier (for
        // example, "make a news page with images"). A confirmed new-content
        // turn takes precedence unless an open staged draft makes it a revision.
        $is_creation_profile = TurnProfile::TOOL_COMPOSE === $profile->tool_profile;
        $compact_preparation = $uses_phases && $is_creation_profile && $is_content_turn && !$has_open_new_post;
        $compact_revision =
            $uses_phases && $is_creation_profile && $has_open_new_post && ($is_content_turn || $is_content_edit_turn);
        $provider_tools = $compact_revision
            ? $this->tool_registry->get_chat_completion_tools(['awpt/read-proposal'])
            : (
                $compact_preparation
                    ? $this->tool_registry->get_chat_completion_tools(['awpt/prepare-pattern-draft'])
                    : (
                        $uses_phases
                            ? $this->tool_registry->get_exploration_tools(
                                $profile->explore_allowlist(),
                                !$is_improve_act,
                            )
                            : $this->tool_registry->get_chat_completion_tools_for_profile($profile)
                    )
            );
        $compaction = new SessionCompactionService()->compact_if_needed($provider, [
            'session_id' => $session_id,
            'turn_id' => $turn_id,
            'messages' => $messages,
            'tools' => $provider_tools,
            'max_completion_tokens' => $budget_tokens,
        ]);
        $messages = $compaction['messages'];
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
                'request_timeout_seconds' => $this->improve_or_default_request_timeout(
                    $is_improve_evaluate,
                    $is_improve_act,
                    $remaining,
                    5,
                ),
                'content_turn' => $is_content_turn,
                'content_edit_turn' => $is_content_edit_turn,
                'tools_offered' => count($provider_tools),
                'context_estimated_tokens' => $compaction['estimated_input_tokens'],
                'context_compacted' => $compaction['compacted'],
                'checkpoint_event_id' => $compaction['checkpoint_id'],
            ], $profile->diagnostics()),
        ]);
        $initial_tool_choice = match (true) {
            $is_improve_evaluate => $this->exact_tool_choice_for($provider_tools, 'awpt/read-block-tree'),
            $compact_preparation, $compact_revision => $this->exact_tool_choice($provider_tools),
            default => 'auto',
        };
        $result = $provider->complete($messages, $provider_tools, [
            'session_id' => $session_id,
            'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
            'tool_round' => 0,
            'log_phase' => $turn_phase,
            'max_completion_tokens' => $budget_tokens,
            'timeout' => $this->improve_or_default_request_timeout(
                $is_improve_evaluate,
                $is_improve_act,
                $remaining,
                5,
            ),
            'tool_choice' => $initial_tool_choice,
        ]);
        $this->record_provider_call($session_id, [
            'provider' => $provider->get_name(),
            'tool_round' => 0,
            'budget' => $budget_tokens,
            'started_at' => $started_at,
            'result' => $result,
            'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
            'context_tokens_estimate' => $compaction['estimated_input_tokens'],
            'checkpoint_event_id' => $compaction['checkpoint_id'],
        ]);
        $notice = $this->vision_evidence->notice();

        if (is_wp_error($result)) {
            $failover = $this->maybe_failover($provider, $result, $messages, $provider_tools, [
                'session_id' => $session_id,
                'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
            ]);

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

        if ($is_improve_evaluate && $this->has_no_native_tool_call($result)) {
            $retry_messages = [
                ...$messages,
                [
                    'role' => 'system',
                    'content' => 'The previous response did not make the required native function call. Call the selected read-block-tree function now; do not write pseudo tool markup in reasoning or text.',
                ],
            ];
            $retry_started_at = microtime(true);
            $result = $provider->complete($retry_messages, $provider_tools, [
                'session_id' => $session_id,
                'turn_id' => $turn_id,
                'tool_round' => 1,
                'log_phase' => 'improve_evaluate_native_retry',
                'max_completion_tokens' => $budget_tokens,
                'timeout' => $this->improve_or_default_request_timeout(
                    true,
                    false,
                    $turn_wall_seconds - (int) ceil(microtime(true) - $started_at),
                    5,
                ),
                'tool_choice' => $this->exact_tool_choice_for($provider_tools, 'awpt/read-block-tree'),
            ]);
            $this->record_provider_call($session_id, [
                'provider' => $provider->get_name(),
                'tool_round' => 1,
                'budget' => $budget_tokens,
                'started_at' => $retry_started_at,
                'result' => $result,
                'turn_id' => $turn_id,
            ]);
            $messages = $retry_messages;

            if (is_wp_error($result)) {
                return [
                    'content' => $result->get_error_message(),
                    'tool_calls' => [],
                    'actions' => [],
                    'turn_outcome' => [
                        'status' => 'failed',
                        'error_code' => $result->get_error_code(),
                        'message' => $result->get_error_message(),
                    ],
                    'provider' => $provider->get_name(),
                ];
            }

            if ($this->has_no_native_tool_call($result)) {
                $message = __(
                    'The provider did not return the required native block-tree tool call. No action was staged; retry with a tool-capable model or provider.',
                    'agent-wordpress-terminal',
                );

                return [
                    'content' => '[awpt:plan_failed] ' . $message,
                    'tool_calls' => [],
                    'actions' => [],
                    'turn_outcome' => [
                        'status' => 'failed',
                        'error_code' => 'awpt_improve_evaluate_no_native_output',
                        'message' => $message,
                    ],
                    'provider' => $provider->get_name(),
                    'model' => (string) ($result['model'] ?? ''),
                ];
            }
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
            'presentation_edit' => $profile->presentation_edit,
            'uses_explore_compose' => $uses_phases,
            'unbounded_agent_runtime' => $unbounded_agent_runtime,
            'offered_tool_names' => $this->tool_registry->names_from_declarations($provider_tools),
            'compose_abilities' => $compact_preparation
                ? ['awpt/propose-patterned-post']
                : (
                    $compact_revision
                        ? (
                            $this->explicit_custom_request($message)
                                ? ['awpt/propose-new-post']
                                : ['awpt/propose-patterned-post']
                        )
                        : []
                ),
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
            'turn_outcome' => $this->turn_outcome($tool_calls, $loop_result['actions'], $content),
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
        $proposal_constraints = new ProposalConstraintSet();
        $terminal_content_loss_recovery_sent = false;
        $terminal_pattern_recovery_sent = false;
        $terminal_heading_recovery_sent = false;
        $corrective_replan_sent = false;
        $recovery_stall_nudge_sent = false;
        $discovery_nudge_sent = false;
        $pattern_read_recovery = false;
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
        $loop_profile = $options['turn_profile'] ?? null;
        $loop_profile = $loop_profile instanceof TurnProfile ? $loop_profile : null;
        $is_improve_act = $loop_profile?->is_improve_act() ?? false;
        $unbounded_agent_runtime = ArrayKey::rest_bool($options['unbounded_agent_runtime'] ?? false);
        $max_provider_completions = $unbounded_agent_runtime
            ? PHP_INT_MAX
            : match (true) {
                $loop_profile?->is_improve_evaluate() ?? false => self::IMPROVE_EVALUATE_MAX_COMPLETIONS,
                $is_improve_act => self::IMPROVE_ACT_MAX_COMPLETIONS,
                $is_content_turn || $is_content_edit_turn => self::CONTENT_MAX_PROVIDER_COMPLETIONS,
                default => self::MAX_PROVIDER_COMPLETIONS,
            };
        $max_explore_hops = $unbounded_agent_runtime
            ? PHP_INT_MAX
            : ($is_improve_act ? self::IMPROVE_ACT_MAX_EXPLORE_HOPS : self::MAX_EXPLORE_HOPS);
        $formatted_after_success = false;
        $turn_phase = $uses_explore_compose ? 'explore' : 'direct';
        $visual_verification_rounds = 0;
        $proposal_review_action_id = 0;
        $proposal_review_complete = false;
        $offered_tool_names = array_key_exists('offered_tool_names', $options)
            ? array_values(array_filter(
                is_array($options['offered_tool_names']) ? $options['offered_tool_names'] : [],
                'is_string',
            ))
            : null;
        $activated_tool_names = [];
        $compose_abilities = array_values(array_filter(
            is_array($options['compose_abilities'] ?? null) ? $options['compose_abilities'] : [],
            'is_string',
        ));

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
            if ($proposal_review_action_id > 0) {
                $turn_context['proposal_review_action_id'] = $proposal_review_action_id;
            } else {
                unset($turn_context['proposal_review_action_id']);
            }
            // A provider can hit its output ceiling after emitting the start of
            // a syntactically salvageable tool call. Never execute that partial
            // payload: validators can diagnose its incidental broken markup,
            // but the actionable cause is provider truncation.
            if ($compose_only && $this->completion_was_truncated($result)) {
                break;
            }

            $raw_tool_calls = is_array($result['raw_tool_calls'] ?? null) ? $result['raw_tool_calls'] : [];

            if ([] !== $raw_tool_calls) {
                $assistant_message = $this->tool_executor->assistant_tool_call_message($result);
                new SessionEventRepository()->append($session_id, [
                    'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
                    'ordinal' => ($tool_round * 100) + 1,
                    'event_type' => 'assistant_tool_calls',
                    'payload' => [
                        'content' => (string) ($assistant_message['content'] ?? ''),
                        'tool_calls' => is_array($assistant_message['tool_calls'] ?? null)
                            ? $assistant_message['tool_calls']
                            : [],
                    ],
                ]);
            }

            $execution = $this->tool_executor->execute($raw_tool_calls, $tool_registry, $session_id, $turn_context);

            if ([] === $execution['tool_calls']) {
                break;
            }

            ++$tool_round;
            new SessionTurnLock()->refresh($session_id, (string) ($turn_context['turn_id'] ?? ''));

            foreach ($execution['messages'] as $message_index => $tool_message) {
                new SessionEventRepository()->append($session_id, [
                    'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
                    'ordinal' => ($tool_round * 100) + 10 + (int) $message_index,
                    'event_type' => 'tool_result',
                    'payload' => ['content' => (string) ($tool_message['content'] ?? '{}')],
                    'call_id' => (string) ($tool_message['tool_call_id'] ?? ''),
                ]);
            }

            if ($uses_explore_compose && !$compose_only) {
                ++$explore_hops;
            }

            $tool_calls = [...$tool_calls, ...$execution['tool_calls']];
            // H1 requirements come from domain pack rules / explicit payload flags,
            // not automatic presentation-edit forcing.
            $actions = $this->merge_actions($actions, $this->actions_from_tool_calls($execution['tool_calls']));
            $messages[] = $this->tool_executor->assistant_tool_call_message($result);
            $messages = array_merge($messages, $execution['messages']);

            if ($is_improve_act) {
                $review_decision = $this->proposal_review_decision(
                    $execution['tool_calls'],
                    $proposal_review_action_id,
                );

                if (null !== $review_decision) {
                    $proposal_review_complete = true;

                    if (true === ($review_decision['accepted'] ?? false)) {
                        $accepted = $this->accepted_review_action($review_decision);

                        if (null !== $accepted) {
                            $actions = [$accepted];
                            $content = trim((string) ($review_decision['summary'] ?? ''));
                        } else {
                            $actions = [];
                            $content = __(
                                'The candidate was accepted, but its staged action could not be reloaded for automatic application.',
                                'agent-wordpress-terminal',
                            );
                            $tool_calls[] = [
                                'tool' => 'awpt/proposal-review-finalization',
                                'input' => ['action_id' => (int) ($review_decision['action_id'] ?? 0)],
                                'output' => [
                                    'error_code' => 'awpt_accepted_action_unavailable',
                                    'error' => $content,
                                ],
                                'status' => 'failed',
                                'provider_call_id' => 'awpt_review_reload_' . wp_generate_password(8, false),
                            ];
                        }
                    } else {
                        $actions = [];
                        $content = trim((string) ($review_decision['summary'] ?? ''));
                    }

                    $formatted_after_success = true;
                    break;
                }
            }

            if ($pattern_read_recovery && $this->has_successful_tool($execution['tool_calls'], 'awpt/read-pattern')) {
                $pattern_read_recovery = false;
                $compose_only = true;
                $turn_phase = 'compose';
                $messages[] = [
                    'role' => 'system',
                    'content' => 'The selected pattern has now been read. Stage one corrected proposal using its actual block markup and name provenance. Do not represent a pattern name as a block type.',
                ];
            }

            foreach ($execution['tool_calls'] as $call) {
                if ('success' !== (string) ($call['status'] ?? '') || 'awpt/find-abilities' !== $call['tool']) {
                    continue;
                }

                $output = is_array($call['output'] ?? null) ? $call['output'] : [];
                $activated = is_array($output['activated'] ?? null) ? $output['activated'] : [];

                foreach (array_filter($activated, 'is_string') as $ability_name) {
                    if (!$tool_registry->can_auto_execute($ability_name)) {
                        continue;
                    }

                    $activated_tool_names[] = $ability_name;
                }

                $activated_tool_names = array_values(array_unique($activated_tool_names));
            }

            $proposal_failed_this_round = false;

            foreach ($execution['tool_calls'] as $call) {
                $tool_name = (string) ($call['tool'] ?? '');

                if (ToolRegistry::is_proposal_ability($tool_name) && 'success' !== (string) ($call['status'] ?? '')) {
                    $proposal_failed_this_round = true;
                }
            }

            if ($proposal_failed_this_round) {
                ++$proposal_failures;
                // Each newly rejected proposal gets one chance to recover from
                // an empty/explanatory follow-up. A prior failure's stall nudge
                // must not consume the next correction cycle.
                $recovery_stall_nudge_sent = false;
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
                $latest_candidate = end($proposal_calls);
                $latest_candidate_output = is_array($latest_candidate) && is_array($latest_candidate['output'] ?? null)
                    ? $latest_candidate['output']
                    : [];
                $latest_candidate_id = (int) ($latest_candidate_output['id'] ?? 0);
                if (
                    $is_improve_act
                    && $proposal_review_action_id > 0
                    && $latest_candidate_id > 0
                    && $proposal_review_action_id !== $latest_candidate_id
                ) {
                    $this->discard_review_candidate($proposal_review_action_id);
                }
                $proposal_review_action_id = $latest_candidate_id;
                if ($is_improve_act && $proposal_review_action_id > 0) {
                    new ActionRepository()->update_status($proposal_review_action_id, 'verifying');
                }
                $candidate_payload = is_array($latest_candidate_output['payload'] ?? null)
                    ? $latest_candidate_output['payload']
                    : [];
                $verification =
                    ($unbounded_agent_runtime || $visual_verification_rounds < self::MAX_VISUAL_VERIFICATION_ROUNDS)
                    && ($is_improve_act || '' !== (string) ($candidate_payload['preview_url'] ?? ''))
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
                        $is_improve_act
                        || $this->should_review_visual_evidence(
                            $verification,
                            $remaining,
                            $provider_completions,
                            $max_provider_completions,
                            true === ($options['presentation_edit'] ?? false),
                        )
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
                        $review_abilities = [] !== $compose_abilities
                            ? $compose_abilities
                            : $turn_profile?->compose_allowlist() ?? ProposalAbilities::names();
                        if ($is_improve_act) {
                            $review_abilities = array_values(array_unique([
                                ...$review_abilities,
                                'awpt/read-proposal',
                                'awpt/read-block-tree',
                                'awpt/get-block',
                                'awpt/finalize-proposal-review',
                            ]));
                            // Keep the review surface active after candidate
                            // reads so the next hop can revise or finalize.
                            $compose_abilities = $review_abilities;
                        }
                        $review_tools = $tool_registry->get_chat_completion_tools($review_abilities);
                        $review_started_at = microtime(true);
                        $review = $provider->complete($messages, $review_tools, [
                            'session_id' => $session_id,
                            'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
                            'tool_round' => count($tool_calls),
                            'log_phase' => 'visual_review',
                            'max_completion_tokens' => self::COMPOSITION_MAX_COMPLETION_TOKENS,
                            // The agent may accept the verified proposal with prose
                            // or choose any targeted proposal operation to revise it.
                            'tool_choice' => $is_improve_act ? 'required' : 'auto',
                            'timeout' => $unbounded_agent_runtime
                                ? self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS
                                : min(90, max(self::MIN_USEFUL_REQUEST_SECONDS, $remaining)),
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

                            if ($is_improve_act) {
                                $actions = [];
                                $tool_calls[] = [
                                    'tool' => 'awpt/proposal-review-finalization',
                                    'input' => ['action_id' => $proposal_review_action_id],
                                    'output' => [
                                        'error_code' => 'awpt_proposal_review_not_finalized',
                                        'error' => __(
                                            'The agent reviewed the candidate but did not explicitly accept or abandon it, so Review Queue did not apply it.',
                                            'agent-wordpress-terminal',
                                        ),
                                    ],
                                    'status' => 'failed',
                                    'provider_call_id' => 'awpt_review_finalize_' . wp_generate_password(8, false),
                                ];
                                $content = __(
                                    'The internal candidate was not finalized and was not applied.',
                                    'agent-wordpress-terminal',
                                );
                                break;
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

            $latest_failure_code = $this->latest_proposal_failure_code($execution['tool_calls']);
            $proposal_constraints->ingest(array_values($execution['tool_calls']));
            $allow_terminal_content_loss_recovery = $this->should_allow_terminal_content_loss_recovery(
                $proposal_failures,
                $latest_failure_code,
                $terminal_content_loss_recovery_sent,
            );
            $allow_terminal_pattern_recovery = $this->should_allow_terminal_pattern_recovery(
                $proposal_failures,
                $latest_failure_code,
                $terminal_pattern_recovery_sent,
            );
            $allow_terminal_heading_recovery = $this->should_allow_terminal_heading_recovery(
                $proposal_failures,
                $latest_failure_code,
                $terminal_heading_recovery_sent,
            );

            if (
                !$unbounded_agent_runtime
                && $proposal_failures >= self::MAX_PROPOSAL_FAILURES
                && !$allow_terminal_content_loss_recovery
                && !$allow_terminal_pattern_recovery
                && !$allow_terminal_heading_recovery
                || $provider_completions >= $max_provider_completions
            ) {
                if ($proposal_failures >= self::MAX_PROPOSAL_FAILURES) {
                    $content = __(
                        'I could not stage the proposal after three staging attempts. The validation failures are preserved below so the next attempt can use verified site evidence.',
                        'agent-wordpress-terminal',
                    );
                }
                break;
            }

            if ($allow_terminal_content_loss_recovery) {
                $terminal_content_loss_recovery_sent = true;
            }
            if ($allow_terminal_pattern_recovery) {
                $terminal_pattern_recovery_sent = true;
            }
            if ($allow_terminal_heading_recovery) {
                $terminal_heading_recovery_sent = true;
            }

            if (
                $proposal_failures > 0
                && (!$corrective_replan_sent || $proposal_constraints->should_refresh_guidance($latest_failure_code))
            ) {
                // A proposal validation failure still needs a proposal tool on
                // the very next completion. Previously this reset the loop to
                // exploration-only tools while instructing the model to submit
                // a corrected proposal, which made that retry impossible.
                $compose_only = 'awpt_pattern_not_read' !== $latest_failure_code;
                $turn_phase = $compose_only ? 'compose' : 'explore';
                $pattern_read_recovery = 'awpt_pattern_not_read' === $latest_failure_code;
                $guidance = $proposal_constraints->recovery_guidance($proposal_failures, self::MAX_PROPOSAL_FAILURES);

                if ($compose_only) {
                    // Build the deterministic compose checkpoint once. After
                    // compose starts, keep its prefix byte-stable: the failed
                    // tool result and this small correction are appended so
                    // provider caches can reuse all verified evidence.
                    $coverage = is_array($last_discovery['coverage'] ?? null) ? $last_discovery['coverage'] : [];
                    $reason = (string) ($last_discovery['reason'] ?? 'Correct the proposal from verified evidence.');
                    $focus_post_id = $this->focus_post_id_for_pack($session_id, $tool_calls, $turn_context);
                    $builder = new EvidencePackBuilder();
                    $pack = $builder->pack($tool_calls, $coverage, $reason, [
                        'focus_post_id' => $focus_post_id,
                    ]);
                    if (!$compose_compacted) {
                        $messages = $builder->provider_messages($messages, $tool_calls, $user_message, [
                            'coverage' => $coverage,
                            'reason' => $reason,
                            'mode' => 'compose',
                            'focus_post_id' => $focus_post_id,
                        ]);
                        $compose_compacted = true;
                    }
                    $messages[] = [
                        'role' => 'system',
                        'content' => "Structured proposal correction (append-only):\n" . $guidance,
                    ];
                    $compose_abilities = $this->compose_abilities_for(
                        $tool_calls,
                        $turn_profile,
                        $compose_abilities,
                        $pack,
                        true === ($options['presentation_edit'] ?? false),
                    );
                } else {
                    $compose_compacted = false;
                    $messages[] = [
                        'role' => 'system',
                        'content' => $guidance,
                    ];
                }

                $corrective_replan_sent = true;
            }

            $discovery_decision = new DiscoveryPolicy()->decide(
                $user_message,
                $tool_calls,
                $execution['tool_calls'],
                (int) floor(microtime(true) - $turn_started_at),
                [
                    'content_turn' => $is_content_turn || $is_content_edit_turn,
                    'presentation_edit' => true === ($options['presentation_edit'] ?? false),
                    'improve_act' => $is_improve_act,
                    'unbounded_agent_runtime' => $unbounded_agent_runtime,
                ],
            );
            $last_discovery = $discovery_decision;

            $should_enter_compose =
                $uses_explore_compose
                && 0 === $proposal_failures
                && !$compose_only
                && [] !== ($turn_profile?->compose_allowlist() ?? [])
                && (
                    $is_content_turn || $is_content_edit_turn
                        ? $discovery_decision['compose']
                        || true !== ($options['presentation_edit'] ?? false)
                        && $explore_hops >= $max_explore_hops
                        : true
                );

            // op:none (and any other empty compose surface) must not fall through to
            // get_chat_completion_tools([]) which historically means "offer every tool".
            if (
                $uses_explore_compose
                && ($is_improve_act || $is_content_edit_turn)
                && [] === ($turn_profile?->compose_allowlist() ?? [])
            ) {
                $should_enter_compose = false;
            }

            if ($should_enter_compose) {
                $reason = $discovery_decision['compose']
                    ? $discovery_decision['reason']
                    : 'The explore hop budget is complete; stage from verified evidence now.';
                $coverage = $discovery_decision['coverage'];
                $compose_options = [
                    'coverage' => $coverage,
                    'reason' => $reason,
                    'mode' => 'compose',
                ];

                $focus_post_id = $this->focus_post_id_for_pack($session_id, $tool_calls, $turn_context);
                $compose_options['focus_post_id'] = $focus_post_id;
                $page_scale = new PageScale()->from_tool_calls($tool_calls, $focus_post_id);
                $scale_guidance = new PageScale()->compose_guidance($page_scale);
                if ('' !== $scale_guidance) {
                    $compose_options['recovery_guidance'] = $scale_guidance;
                }
                $builder = new EvidencePackBuilder();
                $pack = $builder->pack($tool_calls, $coverage, $reason, [
                    'focus_post_id' => $focus_post_id,
                ]);
                $messages = $builder->provider_messages($messages, $tool_calls, $user_message, $compose_options);
                $compose_abilities = $this->compose_abilities_for(
                    $tool_calls,
                    $turn_profile,
                    $compose_abilities,
                    $pack,
                    true === ($options['presentation_edit'] ?? false),
                );
                // Large redesigns: surface batch tools before full content rewrite.
                if (new PageScale()->is_large($page_scale['scale'])) {
                    $compose_abilities = $this->prefer_batch_tools_for_large_page($compose_abilities);
                }
                $turn_context['pattern_preparation'] = $this->latest_pattern_preparation($tool_calls);
                $turn_context['page_scale'] = $page_scale;
                $compose_only = true;
                $compose_compacted = true;
                $discovery_nudge_sent = true;
                $turn_phase = 'compose';

                $prep_nudge = $this->pattern_change_replace_nudge($tool_calls);

                if ('' !== $prep_nudge) {
                    $messages[] = [
                        'role' => 'system',
                        'content' => $prep_nudge,
                    ];
                }

                if ($this->has_custom_fallback($tool_calls) || true === ($options['presentation_edit'] ?? false)) {
                    $options['turn_wall_seconds'] = max(
                        (int) ($options['turn_wall_seconds'] ?? self::CONTENT_TURN_WALL_SECONDS),
                        self::RAW_COMPOSITION_TURN_WALL_SECONDS,
                    );
                }
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
                $prep_nudge = $this->pattern_change_replace_nudge($tool_calls);

                if ('' !== $prep_nudge) {
                    $messages[] = [
                        'role' => 'system',
                        'content' => $prep_nudge,
                    ];
                }

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
                        is_array($last_discovery['coverage']) ? $last_discovery['coverage'] : [],
                        $last_discovery['reason'],
                    )
                    : 0,
                'activated_tool_names' => $activated_tool_names,
                'compose_abilities' => $compose_abilities,
                'proposal_failures' => $proposal_failures,
                'latest_failure_code' => $latest_failure_code,
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
                    'parallel_batch_size' => (int) $execution['parallel_batch_size'],
                ],
            ]);
            $follow_up = $this->follow_up_round($provider, $tool_registry, $state);
            ++$provider_completions;

            $content = $follow_up['content'];
            $result = $follow_up['result'];
            $offered_tool_names = array_values(array_filter($follow_up['offered_tool_names'], 'is_string'));
            if (is_array($follow_up['failure_tool_call'] ?? null)) {
                $tool_calls[] = $follow_up['failure_tool_call'];
                // A provider-level failure is already terminal for this turn.
                // Do not feed it into the model-stall retry path as though the
                // model merely returned prose instead of a proposal.
                break;
            }

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
                // Keep the current phase. Forcing explore here stripped proposal
                // tools after content-loss/H1 recovery, so the model called
                // propose-* and was rejected as "not allowed for automatic execution".
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
                    'compose_only' => $compose_only,
                    'finalization_retry' => false,
                    'turn_profile' => $turn_profile,
                    'turn_phase' => $turn_phase,
                    'explore_hops' => $explore_hops,
                    'compose_compacted' => $compose_compacted,
                    'activated_tool_names' => $activated_tool_names,
                    'compose_abilities' => $compose_abilities,
                    'proposal_failures' => $proposal_failures,
                    'latest_failure_code' => $latest_failure_code,
                ];
                $follow_up = $this->follow_up_round($provider, $tool_registry, $state);
                ++$provider_completions;
                $content = $follow_up['content'];
                $result = $follow_up['result'];
                $offered_tool_names = array_values(array_filter($follow_up['offered_tool_names'], 'is_string'));
                if (is_array($follow_up['failure_tool_call'] ?? null)) {
                    $tool_calls[] = $follow_up['failure_tool_call'];
                    break;
                }

                if ($follow_up['continue']) {
                    continue;
                }
            }

            break;
        }

        // Improve evaluate: if the model burned hops re-reading truncated trees and
        // never emitted a plan, force one structured finalization from existing evidence.
        if (
            ($loop_profile?->is_improve_evaluate() ?? false)
            && $this->has_successful_evaluate_evidence($tool_calls)
            && !$this->looks_like_execution_plan($content)
        ) {
            $finalized = $this->force_improve_evaluate_plan($provider, $session_id, [
                'messages' => $messages,
                'tool_calls' => $tool_calls,
                'result' => $result,
                'content' => $content,
                'turn_started_at' => $turn_started_at,
                'turn_wall_seconds' => (int) ($options['turn_wall_seconds'] ?? self::IMPROVE_EVALUATE_WALL_SECONDS),
                'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
            ]);
            $content = $finalized['content'];
            $result = $finalized['result'];
            $messages = $finalized['messages'];
        }

        // Incomplete awpt-units: send nits back for one repair hop instead of failing closed.
        if ($loop_profile?->is_improve_evaluate() ?? false) {
            $repaired = $this->repair_improve_evaluate_units($provider, $session_id, [
                'messages' => $this->message_maps($messages),
                'tool_calls' => $tool_calls,
                'result' => $result,
                'content' => $content,
                'turn_started_at' => $turn_started_at,
                'turn_wall_seconds' => (int) ($options['turn_wall_seconds'] ?? self::IMPROVE_EVALUATE_WALL_SECONDS),
                'turn_id' => (string) ($turn_context['turn_id'] ?? ''),
            ]);
            $content = $repaired['content'];
            $result = $repaired['result'];
            $messages = $repaired['messages'];
        }

        if ([] === $actions && $compose_only && is_array($result) && $this->completion_was_truncated($result)) {
            $proposal_tool = 'awpt/propose-content-update';

            foreach ($offered_tool_names ?? [] as $offered_tool_name) {
                if (!ToolRegistry::is_proposal_ability($offered_tool_name)) {
                    continue;
                }

                $proposal_tool = $offered_tool_name;
                break;
            }

            $content = __(
                'The proposal reached the provider output limit before a valid change could be staged. No change was applied; retry the request.',
                'agent-wordpress-terminal',
            );
            $tool_calls[] = [
                'tool' => $proposal_tool,
                'input' => [],
                'output' => [
                    'error' => $content,
                    'error_code' => 'awpt_proposal_output_truncated',
                ],
                'status' => 'failed',
                'provider_call_id' => 'awpt_truncated_' . wp_generate_password(8, false),
            ];
        }

        if (!$formatted_after_success && [] !== $tool_calls && '' === trim($content)) {
            $content = $this->result_formatter->format_for_transcript($tool_calls, $content);
        }

        // Record unresolved failures for the open-incidents context; diagnosis is opt-in via REST.
        new DiagnosisRuntime()->record_first_failure($session_id, $this->unresolved_tool_failures($tool_calls));

        if ($is_improve_act && !$proposal_review_complete) {
            $this->discard_review_candidate($proposal_review_action_id);
            $actions = [];
        }

        return [
            'content' => $content,
            'tool_calls' => $tool_calls,
            'actions' => $actions,
            'model' => (string) ($result['model'] ?? ''),
            'messages' => $messages,
        ];
    }

    /**
     * Genuine browser evidence earns a review round. Presentation turns may
     * also use explicit static content-region heading evidence: it is not a
     * visual check, but it authoritatively proves whether the proposal still
     * lacks a page H1 or has a broken semantic outline.
     *
     * @param array{tool_call: array<string, mixed>, message: array<string, mixed>} $verification
     */
    private function should_review_visual_evidence(
        array $verification,
        int $remaining,
        int $provider_completions,
        int $max_provider_completions,
        bool $presentation_edit = false,
    ): bool {
        $output = is_array($verification['tool_call']['output'] ?? null) ? $verification['tool_call']['output'] : [];
        $has_heading_evidence =
            $presentation_edit
            && array_key_exists('main_h1_count', $output)
            && is_array($output['main_heading_outline'] ?? null);

        return (
            (true === ($output['rendered'] ?? false) || $has_heading_evidence)
            && $remaining >= self::MIN_USEFUL_REQUEST_SECONDS
            && $provider_completions < $max_provider_completions
        );
    }

    /** @param array<int, array<string, mixed>> $calls */
    private function has_successful_tool(array $calls, string $tool_name): bool {
        foreach ($calls as $call) {
            if ('success' === (string) ($call['status'] ?? '') && $tool_name === (string) ($call['tool'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $tool_calls */
    private function has_custom_fallback(array $tool_calls): bool {
        foreach ($tool_calls as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');

            if (
                in_array($tool, ['awpt/prepare-pattern-draft', 'awpt/prepare-pattern-change'], true)
                && 'custom_fallback' === (string) ($call['output']['mode'] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    private function explicit_custom_request(string $message): bool {
        return (bool) preg_match(
            '/\b(from\s+scratch|bespoke|fully\s+custom|custom\s+layout|without\s+(?:a\s+)?pattern|do\s+not\s+use\s+(?:a\s+)?pattern)\b/i',
            $message,
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{content: string, result: array<string, mixed>, continue: bool, offered_tool_names: list<string>, failure_tool_call?: array<string, mixed>}
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
        $raw_custom_composition = $compose_only && $this->has_custom_fallback($tool_calls);
        $turn_profile = $state['turn_profile'] ?? null;
        $turn_profile = $turn_profile instanceof TurnProfile ? $turn_profile : null;
        $completion_budget = $compose_only
            ? (
                $raw_custom_composition
                    ? self::RAW_COMPOSITION_MAX_COMPLETION_TOKENS
                    : (
                        $turn_profile->content_edit_turn ?? false
                            ? self::EXISTING_CONTENT_COMPOSITION_MAX_COMPLETION_TOKENS
                            : self::COMPOSITION_MAX_COMPLETION_TOKENS
                    )
            )
            : $budget_tokens;
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

        $uses_phases = $turn_profile?->uses_explore_compose_phases() ?? false;
        $activated = array_values(array_filter(
            is_array($state['activated_tool_names'] ?? null) ? $state['activated_tool_names'] : [],
            'is_string',
        ));
        $compose_abilities = array_values(array_filter(
            is_array($state['compose_abilities'] ?? null) ? $state['compose_abilities'] : [],
            'is_string',
        ));

        if ($compose_only) {
            if ([] === $compose_abilities) {
                $compose_abilities = $turn_profile?->compose_allowlist() ?? ['awpt/propose-new-post'];
            }
            $compose_abilities = $this->widen_compose_after_failures(
                $compose_abilities,
                (int) ($state['proposal_failures'] ?? 0),
                $turn_profile,
                (string) ($state['latest_failure_code'] ?? ''),
            );
            // Empty allowlist is intentional (e.g. Improve unit op:none). Never treat
            // it as "offer the full catalog" — get_chat_completion_tools([]) means all.
            if ([] === $compose_abilities) {
                return [
                    'content' => $this->result_formatter->format_no_change_from_plan(),
                    'result' => $prior_result,
                    'continue' => false,
                    'offered_tool_names' => [],
                ];
            }
            $provider_tools = $tool_registry->get_chat_completion_tools($compose_abilities);
            $proposal_function = [] !== $provider_tools ? (1 === count($provider_tools) ? 'exact' : 'required') : null;
        } elseif ($uses_phases) {
            $provider_tools = $tool_registry->get_exploration_tools([
                ...($turn_profile?->explore_allowlist() ?? []),
                ...$activated,
            ], !($turn_profile?->is_improve_act() ?? false));
            $proposal_function = null;
        } else {
            $provider_tools = $turn_profile?->is_improve_evaluate()
                ? $tool_registry->get_chat_completion_tools($this->evaluate_follow_up_allowlist(
                    $turn_profile->tool_allowlist(),
                    $tool_calls,
                ))
                : (
                    null !== $turn_profile
                        ? $tool_registry->get_chat_completion_tools_for_allowlist([
                            ...$turn_profile->tool_allowlist(),
                            ...$activated,
                        ])
                        : $tool_registry->get_chat_completion_tools($activated)
                );
            $proposal_function = null;
        }

        $tool_choice = match ($proposal_function) {
            'exact' => $this->exact_tool_choice($provider_tools),
            'required' => 'required',
            default => 'auto',
        };
        $offered_tool_names = $tool_registry->names_from_declarations($provider_tools);
        $evaluate_follow_up = $turn_profile?->is_improve_evaluate() ?? false;
        $act_follow_up = $turn_profile?->is_improve_act() ?? false;
        $request_timeout = $this->unbounded_agent_runtime()
            ? self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS
            : (
                $compose_only
                    ? min(
                        $act_follow_up
                            ? self::IMPROVE_ACT_WALL_SECONDS
                            : (
                                $raw_custom_composition
                                    ? self::RAW_COMPOSE_REQUEST_SECONDS
                                    : self::EXISTING_COMPOSE_REQUEST_SECONDS
                            ),
                        max(self::MIN_USEFUL_REQUEST_SECONDS, $remaining),
                    )
                    : $this->improve_or_default_request_timeout(
                        $evaluate_follow_up,
                        $act_follow_up,
                        $remaining,
                        self::MIN_USEFUL_REQUEST_SECONDS,
                    )
            );
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
            'turn_id' => $turn_id,
            'tool_round' => count($tool_calls),
            'log_phase' => $turn_phase,
            'max_completion_tokens' => $completion_budget,
            'tool_choice' => $tool_choice,
            'timeout' => $request_timeout,
            'reasoning_effort' => $raw_custom_composition || ($turn_profile->content_edit_turn ?? false) ? 'low' : '',
        ]);
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
                        'completion_budget' => $completion_budget,
                        'request_timeout_seconds' => $this->unbounded_agent_runtime()
                            ? self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS
                            : min(
                                $act_follow_up
                                    ? self::IMPROVE_ACT_WALL_SECONDS
                                    : (
                                        $raw_custom_composition
                                            ? self::RAW_COMPOSE_RETRY_SECONDS
                                            : self::EXISTING_COMPOSE_RETRY_SECONDS
                                    ),
                                max(self::MIN_USEFUL_REQUEST_SECONDS, $retry_remaining),
                            ),
                        'proposal_only' => true,
                    ],
                ]);
                $follow_up = $provider->complete(
                    $this->compact_finalization_retry_messages($messages, $tool_calls, $message, $act_follow_up),
                    $provider_tools,
                    [
                        'session_id' => $session_id,
                        'turn_id' => $turn_id,
                        'tool_round' => count($tool_calls),
                        'log_phase' => 'proposal_retry',
                        'max_completion_tokens' => $completion_budget,
                        'tool_choice' => $tool_choice,
                        'timeout' => $this->unbounded_agent_runtime()
                            ? self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS
                            : min(
                                $act_follow_up
                                    ? self::IMPROVE_ACT_WALL_SECONDS
                                    : (
                                        $raw_custom_composition
                                            ? self::RAW_COMPOSE_RETRY_SECONDS
                                            : self::EXISTING_COMPOSE_RETRY_SECONDS
                                    ),
                                max(self::MIN_USEFUL_REQUEST_SECONDS, $retry_remaining),
                            ),
                        'reasoning_effort' => $raw_custom_composition || ($turn_profile->content_edit_turn ?? false)
                            ? 'low'
                            : '',
                    ],
                );
                $this->record_provider_call($session_id, [
                    'provider' => $provider->get_name(),
                    'tool_round' => count($tool_calls),
                    'budget' => $completion_budget,
                    'started_at' => $retry_started_at,
                    'result' => $follow_up,
                    'turn_id' => $turn_id,
                ]);
            }
        }

        if (!is_array($follow_up)) {
            $failure = $this->result_formatter->format_incomplete_turn(
                $tool_calls,
                $follow_up->get_error_message(),
                (string) $follow_up->get_error_code(),
            );
            $failure_message = $follow_up->get_error_message();
            $failure_code = sanitize_key((string) $follow_up->get_error_code());

            // Do not pretend a transport timeout was a propose-content-update ability failure.
            return [
                // Finalization formats the tool results once. Returning an
                // already-formatted transcript here duplicated every read.
                'content' => $failure,
                'result' => $prior_result,
                'continue' => false,
                'offered_tool_names' => $offered_tool_names,
                'failure_tool_call' => [
                    'tool' => 'awpt/provider-finalization',
                    'input' => [],
                    'output' => [
                        'error' => '' !== trim($failure_message)
                            ? $failure_message
                            : __('The provider could not finalize the requested proposal.', 'agent-wordpress-terminal'),
                        'error_code' => '' !== $failure_code ? $failure_code : 'awpt_provider_finalization_failed',
                    ],
                    'status' => 'failed',
                    'provider_call_id' => 'awpt_finalization_' . wp_generate_password(8, false),
                ],
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
     * @param array{session_id?: int, turn_id?: string} $context Failover logging context.
     * @return array{0: ProviderInterface, 1: array<string, mixed>|\WP_Error, 2: string}|null
     */
    private function maybe_failover(
        ProviderInterface $provider,
        \WP_Error $error,
        array $messages,
        array $tools = [],
        array $context = [],
    ): ?array {
        if (
            !$provider instanceof WordPressAIClientProvider
            || self::NO_TEXT_GENERATION_ERROR_CODE !== $error->get_error_code()
        ) {
            return null;
        }

        $fallback = new OpenRouterProvider();
        $fallback_tools = [] !== $tools ? $tools : $this->tool_registry->get_chat_completion_tools();
        $fallback_result = $fallback->complete($messages, $fallback_tools, [
            'session_id' => (int) ($context['session_id'] ?? 0),
            'turn_id' => $context['turn_id'] ?? '',
            'tool_round' => 0,
            'log_phase' => 'failover',
        ]);

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
        $data = $result->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;

        // Invalid requests, authentication failures, and schema rejections are
        // deterministic. Replaying the same request only burns another round.
        if ($status >= 400 && $status < 500 && !in_array($status, [408, 429], true)) {
            return false;
        }

        return (
            str_contains($text, 'timeout')
            || str_contains($text, 'timed out')
            || str_contains($text, 'curl error 28')
            || str_contains($text, '504')
            || in_array($status, [408, 429], true)
            || $status >= 500
            || 'http_request_failed' === $result->get_error_code()
        );
    }

    /** @param array<string, mixed> $result */
    private function completion_was_truncated(array $result): bool {
        $reason = sanitize_key((string) ($result['finish_reason'] ?? ''));

        return in_array($reason, ['length', 'max_tokens', 'max_completion_tokens'], true);
    }

    /**
     * Whether evaluate discovery already produced enough read evidence to draft a plan.
     *
     * @param array<int, array<string, mixed>> $tool_calls
     */
    private function has_successful_evaluate_evidence(array $tool_calls): bool {
        foreach ($tool_calls as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');

            if (in_array(
                $tool,
                [
                    'awpt/read-block-tree',
                    'awpt/read-content',
                    'core/read-content',
                    'awpt/analyze-page',
                    'awpt/recommend-patterns',
                ],
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Heuristic: a real evaluate deliverable is a multi-line plan, not mid-loop thrash.
     */
    private function looks_like_execution_plan(string $content): bool {
        $content = trim($content);

        if (mb_strlen($content) < 120) {
            return false;
        }

        // Intermediate "let me re-read..." style messages after truncation thrash.
        if (
            (bool) preg_match(
                '/^(let me|i(?:\'|’)ll|i will|getting|fetching|reading|one more|trying again)\b/iu',
                $content,
            )
            && mb_strlen($content) < 400
        ) {
            return false;
        }

        if ((bool) preg_match('/^#{1,3}\s+\S/m', $content)) {
            return true;
        }

        if ((bool) preg_match(
            '/\b(prepare-pattern-change|propose-block-batch|mode=replace|mode=insert|batch\/attrs|no change|path\s+\d|keep\b|preserve_by_default)\b/i',
            $content,
        )) {
            return true;
        }

        return substr_count($content, "\n") >= 4 && mb_strlen($content) >= 200;
    }

    /**
     * One schema-constrained hop so evaluate turns end with a machine-readable
     * plan when hop budget was spent on reads.
     *
     * @param array{
     *   messages: array<int, array<string, mixed>>,
     *   tool_calls: array<int, array<string, mixed>>,
     *   result: array<string, mixed>,
     *   content: string,
     *   turn_started_at?: float,
     *   turn_wall_seconds?: int,
     *   turn_id?: string
     * } $context
     * @return array{
     *   content: string,
     *   result: array<string, mixed>,
     *   messages: array<int, array<string, mixed>>
     * }
     */
    private function force_improve_evaluate_plan(ProviderInterface $provider, int $session_id, array $context): array {
        $messages = $context['messages'];
        $tool_calls = $context['tool_calls'];
        $result = $context['result'];
        $content = $context['content'];
        $turn_started_at = $this->float_value($context['turn_started_at'] ?? null, microtime(true));
        $turn_wall_seconds = (int) ($context['turn_wall_seconds'] ?? self::IMPROVE_EVALUATE_WALL_SECONDS);
        $turn_id = $context['turn_id'] ?? '';
        $remaining = $turn_wall_seconds - (int) ceil(microtime(true) - $turn_started_at);

        if ($remaining < self::MIN_USEFUL_REQUEST_SECONDS) {
            return [
                'content' => $this->failed_evaluate_plan(),
                'result' => $result,
                'messages' => $messages,
            ];
        }

        $messages[] = [
            'role' => 'system',
            'content' => implode(' ', [
                'Discovery is complete for this evaluate-only turn.',
                'Do not call any more tools.',
                'Submit the final compact markdown plan through the selected internal function.',
                'Recommend only changes that would actually help; if nothing should change, say so.',
                'Put the human-readable evaluation in plan and one object per staging card in units.',
                'Use path document only for a complete full-page replacement; path 0 is the first top-level section.',
                'Every unit must be independently safe to apply. Do not emit layout-only, content-incomplete, placeholder, or fictional follow-up work.',
                'The next turn executes only the first unit. No propose-* calls. No staging.',
            ]),
        ];

        $submission_tools = [$this->improve_plan_submission_tool($this->recommended_pattern_names($tool_calls))];

        $finalize_timeout = $this->unbounded_agent_runtime()
            ? self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS
            : min(self::IMPROVE_EVALUATE_WALL_SECONDS, max(self::MIN_USEFUL_REQUEST_SECONDS, $remaining));

        new ChatProgress()->update($session_id, $turn_id, [
            'phase' => 'finalizing',
            'label' => __('Writing the plan', 'agent-wordpress-terminal'),
            'detail' => __(
                'Evidence is ready; submitting the execution plan without more site reads.',
                'agent-wordpress-terminal',
            ),
            'diagnostics' => [
                'mode' => 'improve_evaluate_finalize',
                'tool_count' => count($tool_calls),
                'request_timeout_seconds' => $finalize_timeout,
            ],
        ]);

        $started_at = microtime(true);
        $follow_up = $provider->complete($messages, $submission_tools, [
            'session_id' => $session_id,
            'turn_id' => $turn_id,
            'tool_round' => count($tool_calls),
            'log_phase' => 'improve_evaluate_finalize',
            // Do not throttle finalize below the provider ceiling. A low cap
            // can leave reasoning models without room for the result envelope.
            'max_completion_tokens' => self::PROVIDER_MAX_COMPLETION_TOKENS,
            'tool_choice' => $this->exact_tool_choice($submission_tools),
            'timeout' => $finalize_timeout,
        ]);
        $this->record_provider_call($session_id, [
            'provider' => $provider->get_name(),
            'tool_round' => count($tool_calls),
            'budget' => self::PROVIDER_MAX_COMPLETION_TOKENS,
            'started_at' => $started_at,
            'result' => $follow_up,
            'turn_id' => $turn_id,
        ]);

        if (!is_array($follow_up)) {
            return [
                'content' => $this->failed_evaluate_plan(),
                'result' => $result,
                'messages' => $messages,
            ];
        }

        $plan = $this->improve_plan_from_submission($follow_up);

        if ([] === ImprovePagePrompt::units_from_plan($plan)) {
            $plan = $this->failed_evaluate_plan();
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $plan,
        ];

        return [
            'content' => $plan,
            'result' => $follow_up,
            'messages' => $messages,
        ];
    }

    /**
     * When the evaluate essay exists but units are incomplete, request one compact,
     * schema-constrained replacement instead of relying on another prose fence.
     *
     * @param array{
     *     messages: list<array<string, mixed>>,
     *     tool_calls: list<array<string, mixed>>,
     *     result: array<string, mixed>|null,
     *     content: string,
     *     turn_started_at: float,
     *     turn_wall_seconds: int,
     *     turn_id: string
     * } $context
     * @return array{content: string, result: array<string, mixed>|null, messages: list<array<string, mixed>>}
     */
    private function repair_improve_evaluate_units(
        ProviderInterface $provider,
        int $session_id,
        array $context,
    ): array {
        $messages = $context['messages'];
        $result = $context['result'];
        $content = trim($context['content']);
        $tool_calls = $context['tool_calls'];
        $units = ImprovePagePrompt::units_from_plan($content);
        $tree = ImprovePagePrompt::tree_snapshot_from_tool_calls($tool_calls);
        $nits = ImprovePagePrompt::units_completeness_nits($units, $content, (int) $tree['top_level_section_count']);
        $recommended_names = array_fill_keys($this->recommended_pattern_names($tool_calls), true);

        foreach ($units as $index => $unit) {
            if (!in_array((string) ($unit['op'] ?? ''), ['pattern_replace', 'pattern_insert'], true)) {
                continue;
            }

            $name = sanitize_text_field((string) ($unit['pattern_name'] ?? ''));
            if ([] === $recommended_names) {
                $nits[] = sprintf(
                    'Unit %d cannot use a pattern operation because recommend-patterns evidence is absent; use batch/none rather than inventing a name.',
                    $index + 1,
                );
                continue;
            }

            if ('' !== $name && !isset($recommended_names[$name])) {
                $nits[] = sprintf(
                    'Unit %d names %s without successful recommend-patterns evidence; change it to an evidenced candidate or use batch/none with an honest reason.',
                    $index + 1,
                    $name,
                );
            }
        }

        if ([] === $units && $this->looks_like_execution_plan($content)) {
            $nits[] = 'Submit at least one complete unit (op, paths, pattern_name or brief).';
        }

        if ('' === $content || [] === $nits) {
            return [
                'content' => $content,
                'result' => $result,
                'messages' => $messages,
            ];
        }

        $turn_started_at = $this->float_value($context['turn_started_at'], microtime(true));
        $turn_wall_seconds = (int) $context['turn_wall_seconds'];
        $turn_id = $context['turn_id'];
        $remaining = $turn_wall_seconds - (int) ceil(microtime(true) - $turn_started_at);

        if ($remaining < self::MIN_USEFUL_REQUEST_SECONDS) {
            return [
                'content' => $content,
                'result' => $result,
                'messages' => $messages,
            ];
        }

        $repair_messages = [
            [
                'role' => 'system',
                'content' => implode(' ', [
                    'Return a corrected Improve evaluation through the selected internal function.',
                    'Preserve the useful judgment in the plan and fix only these unit-field nits:',
                    implode(' ', $nits),
                    'This function records a plan; it does not execute or stage changes.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => "Plan draft to repair:\n\n" . $content,
            ],
        ];

        $submission_tools = [$this->improve_plan_submission_tool(array_keys($recommended_names))];

        $finalize_timeout = $this->unbounded_agent_runtime()
            ? self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS
            : min(self::IMPROVE_EVALUATE_WALL_SECONDS, max(self::MIN_USEFUL_REQUEST_SECONDS, $remaining));

        new ChatProgress()->update($session_id, $turn_id, [
            'phase' => 'finalizing',
            'label' => __('Fixing the plan units', 'agent-wordpress-terminal'),
            'detail' => __(
                'Plan drafted; correcting incomplete unit fields before staging.',
                'agent-wordpress-terminal',
            ),
            'diagnostics' => [
                'mode' => 'improve_evaluate_units_repair',
                'nit_count' => count($nits),
                'request_timeout_seconds' => $finalize_timeout,
            ],
        ]);

        $started_at = microtime(true);
        $follow_up = $provider->complete($repair_messages, $submission_tools, [
            'session_id' => $session_id,
            'turn_id' => $turn_id,
            'tool_round' => count($tool_calls),
            'log_phase' => 'improve_evaluate_units_repair',
            'max_completion_tokens' => self::PROVIDER_MAX_COMPLETION_TOKENS,
            'tool_choice' => $this->exact_tool_choice($submission_tools),
            'timeout' => $finalize_timeout,
        ]);
        $this->record_provider_call($session_id, [
            'provider' => $provider->get_name(),
            'tool_round' => count($tool_calls),
            'budget' => self::PROVIDER_MAX_COMPLETION_TOKENS,
            'started_at' => $started_at,
            'result' => $follow_up,
            'turn_id' => $turn_id,
        ]);

        if (!is_array($follow_up)) {
            return [
                'content' => $this->failed_evaluate_plan(),
                'result' => $result,
                'messages' => $messages,
            ];
        }

        $merged = $this->improve_plan_from_submission($follow_up);

        if ('' === $merged) {
            $merged = $this->failed_evaluate_plan();
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $merged,
        ];

        return [
            'content' => $merged,
            'result' => $follow_up,
            'messages' => $messages,
        ];
    }

    /**
     * Strict internal result contract for Improve evaluation. This is deliberately
     * not registered in ToolRegistry and can never reach an Ability executor.
     *
     * @return array<string, mixed>
     */
    private function improve_plan_submission_tool(array $recommended_pattern_names = []): array {
        $recommended_pattern_names = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => sanitize_text_field((string) $name),
            $recommended_pattern_names,
        ))));
        $pattern_operations_available = [] !== $recommended_pattern_names;
        $string_list = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];
        $unit = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'op' => [
                    'type' => 'string',
                    'enum' => $pattern_operations_available
                        ? ['batch', 'pattern_replace', 'pattern_insert', 'none']
                        : ['batch', 'none'],
                    'description' => $pattern_operations_available
                        ? 'Use a pattern operation only with a pattern_name from the allowed recommendation evidence.'
                        : 'No pattern recommendation evidence is available; use batch or none.',
                ],
                'paths' => $string_list,
                'expected_fingerprint' => ['type' => 'string'],
                'pattern_name' => [
                    'type' => 'string',
                    'enum' => ['', ...$recommended_pattern_names],
                    'description' => $pattern_operations_available
                        ? 'Exact recommended pattern name for pattern operations; otherwise an empty string.'
                        : 'Must be empty because recommend-patterns was not called successfully.',
                ],
                'carry_forward' => $string_list,
                'do_not' => $string_list,
                'brief' => ['type' => 'string'],
            ],
            'required' => [
                'id',
                'title',
                'op',
                'paths',
                'expected_fingerprint',
                'pattern_name',
                'carry_forward',
                'do_not',
                'brief',
            ],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'function',
            'function' => [
                'name' => self::IMPROVE_PLAN_FUNCTION,
                'description' => 'Submit a human-readable Improve evaluation and its executable staging units. This does not change the site.',
                'strict' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'plan' => [
                            'type' => 'string',
                            'description' => 'Compact Markdown evaluation and rationale. Do not include JSON fences.',
                        ],
                        'units' => [
                            'type' => 'array',
                            'items' => $unit,
                        ],
                    ],
                    'required' => ['plan', 'units'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Exact names returned by successful recommendation calls in this turn.
     *
     * @param array<int, array<string, mixed>> $tool_calls
     * @return list<string>
     */
    private function recommended_pattern_names(array $tool_calls): array {
        $names = [];

        foreach ($tool_calls as $call) {
            if (
                'success' !== (string) ($call['status'] ?? '')
                || !in_array(
                    (string) ($call['tool'] ?? ''),
                    ['awpt/recommend-patterns', 'wpab__awpt__recommend-patterns'],
                    true,
                )
            ) {
                continue;
            }

            $output = ArrayKey::as_map($call['output'] ?? null);
            foreach (ArrayKey::list_of_maps($output['recommendations'] ?? null) as $recommendation) {
                $pattern = ArrayKey::as_map($recommendation['pattern'] ?? null);
                $name = sanitize_text_field((string) ($pattern['name'] ?? ''));

                if ('' !== $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /** Convert the internal function arguments to AWPT's stored plan format. */
    private function improve_plan_from_submission(array $result): string {
        foreach (ArrayKey::list_of_maps($result['raw_tool_calls'] ?? []) as $call) {
            $function = ArrayKey::as_map($call['function'] ?? null);

            if (self::IMPROVE_PLAN_FUNCTION !== (string) ($function['name'] ?? '')) {
                continue;
            }

            $arguments = json_decode((string) ($function['arguments'] ?? ''), true);

            if (!is_array($arguments)) {
                return '';
            }

            $plan = trim((string) ($arguments['plan'] ?? ''));
            $units = ImprovePagePrompt::normalize_units($arguments['units'] ?? null);

            if ('' === $plan || [] === $units) {
                return '';
            }

            $encoded = wp_json_encode($units, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($encoded) || '' === $encoded) {
                return '';
            }

            return $plan . "\n\n```awpt-units\n" . $encoded . "\n```";
        }

        return '';
    }

    /** A failed model finalization is diagnostic text, never an executable plan. */
    private function failed_evaluate_plan(): string {
        return '[awpt:plan_failed] '
        . __(
            'The evaluation gathered evidence but did not produce an executable plan. No action was staged; retry the evaluation.',
            'agent-wordpress-terminal',
        );
    }

    private function should_allow_terminal_content_loss_recovery(
        int $proposal_failures,
        string $latest_failure_code,
        bool $already_sent,
    ): bool {
        return (
            $proposal_failures >= self::MAX_PROPOSAL_FAILURES
            && 'awpt_presentation_content_loss' === $latest_failure_code
            && !$already_sent
        );
    }

    private function should_allow_terminal_pattern_recovery(
        int $proposal_failures,
        string $latest_failure_code,
        bool $already_sent,
    ): bool {
        return (
            $proposal_failures >= self::MAX_PROPOSAL_FAILURES
            && 'awpt_pattern_not_found' === $latest_failure_code
            && !$already_sent
        );
    }

    private function should_allow_terminal_heading_recovery(
        int $proposal_failures,
        string $latest_failure_code,
        bool $already_sent,
    ): bool {
        return (
            $proposal_failures >= self::MAX_PROPOSAL_FAILURES
            && in_array($latest_failure_code, ['awpt_required_page_h1_missing', 'awpt_heading_level_skipped'], true)
            && !$already_sent
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
            'focus_post_id' => $this->resolve_post_id_from_tool_calls($tool_calls),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tool_calls
     * @return array<int, array<string, mixed>>
     */
    private function compact_finalization_retry_messages(
        array $messages,
        array $tool_calls,
        string $user_message,
        bool $improve_act,
    ): array {
        $compacted = $this->compact_finalization_messages($messages, $tool_calls, $user_message);

        if (!$improve_act) {
            return $compacted;
        }

        $compacted[] = [
            'role' => 'system',
            'content' => implode(' ', [
                'The previous propose-block-batch-update timed out or failed in transit.',
                'Emit at most the first 1–3 named operations from the current unit — not the whole unit.',
                'Use update_block with attrs and content for headings; do not use replace_inner_html on core/heading.',
            ]),
        ];

        return $compacted;
    }

    /**
     * After a complete tree is in the conversation, stop offering whole-page
     * re-reads. Keep get-block so a named path can still be expanded.
     *
     * @param list<string>                     $allow
     * @param array<int, array<string, mixed>> $tool_calls
     * @return list<string>
     */
    private function evaluate_follow_up_allowlist(array $allow, array $tool_calls): array {
        if (!$this->has_complete_evaluate_tree($tool_calls)) {
            return $allow;
        }

        return array_values(array_filter($allow, static fn(string $name): bool => 'awpt/read-block-tree' !== $name));
    }

    /**
     * @param array<int, array<string, mixed>> $tool_calls
     */
    private function has_complete_evaluate_tree(array $tool_calls): bool {
        foreach ($tool_calls as $call) {
            if ('awpt/read-block-tree' !== (string) ($call['tool'] ?? '')) {
                continue;
            }

            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            if (ToolResultTruncator::provider_tree_is_complete($call['output'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $tool_calls
     * @param array<array-key, mixed>          $turn_context
     */
    private function focus_post_id_for_pack(int $session_id, array $tool_calls, array $turn_context): int {
        $from_context = (int) ($turn_context['focus_post_id'] ?? 0);

        if ($from_context > 0) {
            return $from_context;
        }

        $from_tools = $this->resolve_post_id_from_tool_calls($tool_calls);

        if ($from_tools > 0) {
            return $from_tools;
        }

        $session = new SessionRepository()->get_summary($session_id);

        return (int) ($session['focus_post_id'] ?? 0);
    }

    /**
     * @param array<int, array<string, mixed>> $tool_calls
     */
    private function resolve_post_id_from_tool_calls(array $tool_calls): int {
        foreach ($tool_calls as $call) {
            $tool = (string) ($call['tool'] ?? '');
            $input = is_array($call['input'] ?? null) ? $call['input'] : [];
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];

            if (str_starts_with($tool, 'awpt/propose-')) {
                $id = (int) ($input['post_id'] ?? $output['post_id'] ?? 0);

                if ($id > 0) {
                    return $id;
                }
            }

            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            if ('awpt/analyze-page' === $tool) {
                $id = (int) ($input['id'] ?? $output['id'] ?? 0);

                if ($id > 0) {
                    return $id;
                }
            }

            if ('awpt/inspect-rendered-element' === $tool) {
                $id = (int) ($input['post_id'] ?? $output['post_id'] ?? 0);

                if ($id > 0) {
                    return $id;
                }
            }

            if (in_array($tool, ['awpt/read-content', 'core/read-content', 'awpt/read-block-tree'], true)) {
                $id = (int) ($input['id'] ?? $output['id'] ?? 0);

                if ($id > 0) {
                    return $id;
                }
            }
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     * @return array{type: string, function: array{name: string}}|string
     */
    private function exact_tool_choice(array $tools): array|string {
        $function = is_array($tools[0]['function'] ?? null) ? $tools[0]['function'] : [];
        $name = (string) ($function['name'] ?? '');

        if ('' === $name) {
            return 'required';
        }

        return [
            'type' => 'function',
            'function' => ['name' => $name],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tools
     * @return array{type: string, function: array{name: string}}|string
     */
    private function exact_tool_choice_for(array $tools, string $tool_name): array|string {
        foreach ($tools as $tool) {
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];
            $name = (string) ($function['name'] ?? '');

            if ('' !== $name && $tool_name === $this->tool_registry->tool_name_for_function($name)) {
                return [
                    'type' => 'function',
                    'function' => ['name' => $name],
                ];
            }
        }

        return 'required';
    }

    /** @param array<string, mixed>|\WP_Error $result */
    private function has_no_native_tool_call(array|\WP_Error $result): bool {
        return (
            !is_wp_error($result)
            && [] === (is_array($result['raw_tool_calls'] ?? null) ? $result['raw_tool_calls'] : [])
        );
    }

    /**
     * Keep the compact and unrestricted authoring surfaces separate. A prepared
     * full-document pattern can only enter the compact proposal tool; an
     * explicit/no-match fallback retains the raw Gutenberg proposal tool.
     *
     * @param array<int, array<string, mixed>> $tool_calls
     * @param list<string> $current
     * @param array<string, mixed>|null $pack
     * @return list<string>
     */
    private function compose_abilities_for(
        array $tool_calls,
        ?TurnProfile $profile,
        array $current,
        ?array $pack = null,
        bool $presentation_edit = false,
    ): array {
        // Pattern preparation is a new-content optimization. It must never
        // replace the focused edit proposal surface merely because a broad
        // classifier also noticed the word "page" in the request.
        if (null !== $profile && TurnProfile::TOOL_COMPOSE !== $profile->tool_profile) {
            return $this->narrow_edit_compose_abilities(
                $profile->compose_allowlist(),
                $pack,
                $presentation_edit || $profile->presentation_edit,
            );
        }

        for ($index = count($tool_calls) - 1; $index >= 0; --$index) {
            $call = $tool_calls[$index] ?? [];

            if (
                'success' !== (string) ($call['status'] ?? '')
                || 'awpt/prepare-pattern-draft' !== (string) ($call['tool'] ?? '')
            ) {
                continue;
            }

            $output = is_array($call['output'] ?? null) ? $call['output'] : [];

            return (
                'pattern' === (string) ($output['mode'] ?? '')
                    ? ['awpt/propose-patterned-post']
                    : ['awpt/propose-new-post']
            );
        }

        if ([] !== $current) {
            return $this->narrow_edit_compose_abilities($current, $pack, $presentation_edit);
        }

        return $this->narrow_edit_compose_abilities(
            $profile?->compose_allowlist() ?? ['awpt/propose-new-post'],
            $pack,
            $presentation_edit || ($profile->presentation_edit ?? false),
        );
    }

    /**
     * After repeated proposal rejections, keep the pattern path reachable. The
     * unit allowlist may have narrowed compose retries to a single batch tool
     * that cannot satisfy whole-document gates (one H1, heading outline). Re-admit
     * trusted pattern tools so the model can fall back to server-materialized
     * sections instead of resubmitting the same doomed batch.
     *
     * @param list<string> $abilities
     * @return list<string>
     */
    private function widen_compose_after_failures(
        array $abilities,
        int $proposal_failures,
        ?TurnProfile $profile,
        string $latest_failure_code = '',
    ): array {
        $composition_gate_failures = [
            'awpt_presentation_content_loss',
            'awpt_pattern_not_found',
            'awpt_required_page_h1_missing',
            'awpt_heading_level_skipped',
        ];

        if (
            $proposal_failures < 2
            || [] === $abilities
            || !in_array($latest_failure_code, $composition_gate_failures, true)
        ) {
            return $abilities;
        }

        $has_non_proposal = [] !== array_filter(
            $abilities,
            static fn(string $name): bool => !ToolRegistry::is_proposal_ability($name),
        );

        if ($has_non_proposal) {
            return $abilities;
        }

        $trusted = $profile?->explore_allowlist() ?? [];
        $recovery = [
            'awpt/propose-pattern-replace',
            'awpt/propose-pattern-insert',
            'awpt/recommend-patterns',
            'awpt/read-pattern',
        ];
        $widened = $abilities;

        foreach ($recovery as $name) {
            if (in_array($name, $widened, true)) {
                continue;
            }

            // Read tools must come from the profile's exploration surface;
            // propose tools are the documented fallback for unsatisfiable batches.
            if (ToolRegistry::is_proposal_ability($name) || in_array($name, $trusted, true)) {
                $widened[] = $name;
            }
        }

        return $widened;
    }

    /**
     * On large pages, list batch/pattern tools before full-document content update.
     *
     * @param list<string> $abilities
     * @return list<string>
     */
    private function prefer_batch_tools_for_large_page(array $abilities): array {
        $preferred = [
            'awpt/propose-block-batch-update',
            'awpt/propose-pattern-replace',
            'awpt/propose-pattern-insert',
            'awpt/propose-content-update',
        ];
        $ordered = [];
        foreach ($preferred as $name) {
            if (!in_array($name, $abilities, true)) {
                continue;
            }

            $ordered[] = $name;
        }
        foreach ($abilities as $name) {
            if (in_array($name, $ordered, true)) {
                continue;
            }

            $ordered[] = $name;
        }

        return $ordered;
    }

    /**
     * Without fingerprint-bearing structure, only full-document content updates
     * are safe — batch/attrs tools would invite invented fingerprints.
     *
     * @param list<string>             $abilities
     * @param array<string, mixed>|null $pack
     * @return list<string>
     */
    private function narrow_edit_compose_abilities(
        array $abilities,
        ?array $pack,
        bool $presentation_edit = false,
    ): array {
        unset($presentation_edit);

        if (null === $pack || new EvidencePackBuilder()->has_block_fingerprints($pack)) {
            return $abilities;
        }

        $block_tools = [
            'awpt/propose-block-batch-update',
            'awpt/propose-pattern-insert',
            'awpt/propose-pattern-replace',
        ];
        $offers_block_tools = [] !== array_intersect($abilities, $block_tools);

        if (!$offers_block_tools) {
            return $abilities;
        }

        return ['awpt/propose-content-update'];
    }

    /** @param array<int, array<string, mixed>> $tool_calls @return array<string, mixed> */
    private function latest_pattern_preparation(array $tool_calls): array {
        for ($index = count($tool_calls) - 1; $index >= 0; --$index) {
            $call = $tool_calls[$index] ?? [];
            $tool = (string) ($call['tool'] ?? '');

            if (
                'success' !== (string) ($call['status'] ?? '')
                || !in_array($tool, ['awpt/prepare-pattern-draft', 'awpt/prepare-pattern-change'], true)
            ) {
                continue;
            }

            // Both draft prep and section-change prep are useful runtime evidence.
            return is_array($call['output'] ?? null) ? $call['output'] : [];
        }

        return [];
    }

    /**
     * Soft prefer: after a successful section replace prep, remind the model to
     * propose with path + intent (or the prep path) — server prepares if needed.
     *
     * @param array<int, array<string, mixed>> $tool_calls
     */
    private function pattern_change_replace_nudge(array $tool_calls): string {
        $prep = $this->latest_pattern_preparation($tool_calls);
        $mode = sanitize_key((string) ($prep['mode'] ?? ''));
        $path = sanitize_text_field((string) ($prep['target_path'] ?? ''));
        $intent = sanitize_text_field((string) ($prep['intent'] ?? ''));

        if ('replace' !== $mode || '' === $path && '' === $intent) {
            return '';
        }

        $post_id = (int) ($prep['post_id'] ?? 0);

        return sprintf(
            'A section replace is ready%s. Prefer awpt/propose-pattern-replace with path and intent (the server prepares). Do not invent preparation_id values. Full-document freehand remains available if you must abandon the section swap.',
            implode('', [
                $post_id > 0 ? ' (post_id=' . $post_id : '',
                '' !== $path ? ($post_id > 0 ? ', path=' : ' (path=') . $path : '',
                $post_id > 0 || '' !== $path ? ')' : '',
            ]),
        );
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
     * Keep only live proposal cards when a same-turn correction supersedes a
     * staged predecessor.
     *
     * @param list<array<string, mixed>> $current
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    private function merge_actions(array $current, array $incoming): array {
        foreach ($incoming as $action) {
            $removed = array_map(
                'intval',
                is_array($action['removed_action_ids'] ?? null) ? $action['removed_action_ids'] : [],
            );
            $id = (int) ($action['id'] ?? 0);
            $current = array_values(array_filter(
                $current,
                static fn(array $existing): bool => (
                    !in_array((int) ($existing['id'] ?? 0), $removed, true)
                    && ($id <= 0 || $id !== (int) ($existing['id'] ?? 0))
                ),
            ));
            $current[] = $action;
        }

        return $current;
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
     * @param array<int, array<string, mixed>> $tool_calls
     * @return array<string, mixed>|null
     */
    private function proposal_review_decision(array $tool_calls, int $candidate_action_id): ?array {
        foreach (array_reverse($tool_calls) as $tool_call) {
            if (
                'awpt/finalize-proposal-review' !== (string) ($tool_call['tool'] ?? '')
                || 'success' !== (string) ($tool_call['status'] ?? '')
            ) {
                continue;
            }

            $output = ArrayKey::as_map($tool_call['output'] ?? null);

            if ($candidate_action_id <= 0 || (int) ($output['action_id'] ?? 0) !== $candidate_action_id) {
                continue;
            }

            return $output;
        }

        return null;
    }

    /**
     * Reload an accepted action from durable storage instead of trusting a
     * potentially truncated tool result as the Review Queue handoff.
     *
     * @param array<string, mixed> $review_decision
     * @return array<string, mixed>|null
     */
    private function accepted_review_action(array $review_decision): ?array {
        $action_id = (int) ($review_decision['action_id'] ?? 0);

        if ($action_id <= 0 || true !== ($review_decision['accepted'] ?? false)) {
            return null;
        }

        $action = new ActionRepository()->format_action($action_id);

        if (null === $action || !in_array((string) ($action['status'] ?? ''), ['proposed', 'approved'], true)) {
            return null;
        }

        return $action;
    }

    private function discard_review_candidate(int $action_id): void {
        if ($action_id <= 0) {
            return;
        }

        $actions = new ActionRepository();
        $action = $actions->format_action($action_id);

        if (null === $action || 'verifying' !== (string) ($action['status'] ?? '')) {
            return;
        }

        new StagedPostPreview()->discard_preview_resources(
            is_array($action['payload'] ?? null) ? ArrayKey::as_map($action['payload']) : [],
        );
        $actions->update_status($action_id, 'rejected');
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

    /** @param array<int, array<string, mixed>> $tool_calls */
    private function latest_proposal_failure_code(array $tool_calls): string {
        for ($index = count($tool_calls) - 1; $index >= 0; --$index) {
            $call = $tool_calls[$index] ?? [];

            if (
                'failed' !== (string) ($call['status'] ?? '')
                || !ToolRegistry::is_proposal_ability((string) ($call['tool'] ?? ''))
            ) {
                continue;
            }

            $output = is_array($call['output'] ?? null) ? $call['output'] : [];

            return sanitize_key((string) ($output['error_code'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $tool_calls
     * @param list<array<string, mixed>>       $actions
     * @return array{status: string, message: string, error_code?: string}
     */
    private function turn_outcome(array $tool_calls, array $actions, string $content): array {
        if ([] !== $actions) {
            return [
                'status' => 'staged',
                'message' => __('A validated proposal was staged.', 'agent-wordpress-terminal'),
            ];
        }

        $failures = array_values(array_filter(
            $this->unresolved_tool_failures($tool_calls),
            static function (array $call): bool {
                $tool = (string) ($call['tool'] ?? '');
                if (ToolRegistry::is_proposal_ability($tool)) {
                    return true;
                }

                // Provider transport timeouts during compose are not proposal abilities
                // but must still classify the turn as failed (honest labeling).
                return in_array(
                    $tool,
                    [
                        'awpt/provider-finalization',
                        'awpt/proposal-review-finalization',
                        'awpt/finalize-proposal-review',
                    ],
                    true,
                );
            },
        ));

        if ([] !== $failures) {
            $failure = $failures[count($failures) - 1];
            $output = is_array($failure['output'] ?? null) ? $failure['output'] : [];
            $message = trim((string) ($output['error'] ?? $content));

            return [
                'status' => 'failed',
                'error_code' => sanitize_key((string) ($output['error_code'] ?? 'awpt_proposal_failed')),
                'message' => '' !== $message
                    ? $message
                    : __('The requested change could not be staged.', 'agent-wordpress-terminal'),
            ];
        }

        if (ImprovePagePrompt::is_fallback_evaluate_plan($content)) {
            return [
                'status' => 'failed',
                'error_code' => 'awpt_improve_plan_missing',
                'message' => wp_strip_all_tags($content),
            ];
        }

        return [
            'status' => 'no_change',
            'message' => '' !== trim($content)
                ? trim($content)
                : __('The agent completed without proposing a change.', 'agent-wordpress-terminal'),
        ];
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

    /** @return list<array<string, mixed>> */
    private function message_maps(mixed $messages): array {
        if (!is_array($messages)) {
            return [];
        }

        $maps = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $maps[] = ArrayKey::as_map($message);
        }

        return $maps;
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

    /**
     * Improve evaluate/act hops use the long debug wall. Other turns keep the
     * ordinary 120s request cap.
     */
    private function improve_or_default_request_timeout(
        bool $is_improve_evaluate,
        bool $is_improve_act,
        int $remaining,
        int $floor,
    ): int {
        if ($this->unbounded_agent_runtime()) {
            return self::DEVELOPMENT_REQUEST_TIMEOUT_SECONDS;
        }

        $cap = match (true) {
            $is_improve_evaluate => self::IMPROVE_EVALUATE_WALL_SECONDS,
            $is_improve_act => self::IMPROVE_ACT_WALL_SECONDS,
            default => 120,
        };

        return min($cap, max($floor, $remaining));
    }

    /**
     * During active development, favor completing work over wall-clock and hop
     * budgets. The filter is the explicit off switch when bounded operation is
     * reintroduced for production.
     */
    private function unbounded_agent_runtime(): bool {
        return true === apply_filters('awpt_unbounded_agent_runtime', true);
    }
}
