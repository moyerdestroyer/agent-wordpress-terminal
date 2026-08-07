<?php

/** Agent-loop budget contract. @package AWPT */

declare(strict_types=1);

use AWPT\Agent\ProviderInterface;
use AWPT\Agent\ProviderRuntime;
use AWPT\Agent\ToolRegistry;
use AWPT\Agent\TurnProfile;

final class AwptBudgetTestProvider implements ProviderInterface {
    public int $completions = 0;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools, $options);
        ++$this->completions;

        return $this->tool_result($this->completions + 1);
    }

    public function get_name(): string {
        return 'Budget test';
    }

    public function accepts_image_input(): bool {
        return false;
    }

    /** @return array<string, mixed> */
    public function tool_result(int $number): array {
        $calls = [[
            'id' => 'call-' . $number,
            'function' => ['name' => 'demo__read', 'arguments' => '{}'],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ];
    }
}

function test_provider_runtime_has_one_shared_six_completion_budget(): void {
    awpt_test_reset_state();
    add_filter('awpt_mcp_tools', static fn(): array => [[
        'name' => 'demo/read',
        'description' => 'Read test evidence.',
        'readonly' => true,
        'destructive' => false,
    ]]);
    add_filter('awpt_mcp_execute_tool', static fn(): array => ['ok' => true]);
    $provider = new AwptBudgetTestProvider();
    $initial = $provider->tool_result(1);
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Keep reading.']],
        $initial,
        ['tool_registry' => new ToolRegistry()],
    );

    Assert::same(5, $provider->completions, 'initial plus follow-ups must never exceed six completions');
    Assert::same(6, count($result['tool_calls']), 'completed tool evidence should be preserved at the budget edge');
}

test_provider_runtime_has_one_shared_six_completion_budget();

final class AwptExistingEditBudgetProvider implements ProviderInterface {
    public int $completionBudget = 0;

    public string $reasoningEffort = '';

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools);
        $this->completionBudget = (int) ($options['max_completion_tokens'] ?? 0);
        $this->reasoningEffort = (string) ($options['reasoning_effort'] ?? '');

        return [
            'content' => 'No proposal needed for this isolated budget test.',
            'raw_tool_calls' => [],
            'message' => ['role' => 'assistant', 'content' => 'No proposal needed for this isolated budget test.'],
            'model' => 'fake',
            'usage' => [],
        ];
    }

    public function get_name(): string {
        return 'Existing edit budget test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_gives_existing_page_composition_room_for_preserved_copy(): void {
    awpt_test_reset_state();
    $provider = new AwptExistingEditBudgetProvider();
    $method = new ReflectionMethod(ProviderRuntime::class, 'follow_up_round');
    $method->invoke(new ProviderRuntime(), $provider, new ToolRegistry(), [
        'session_id' => 1,
        'messages' => [['role' => 'user', 'content' => 'Make this page more presentable.']],
        'tool_calls' => [],
        'result' => [],
        'content' => '',
        'turn_started_at' => microtime(true),
        'turn_wall_seconds' => 240,
        'compose_only' => true,
        'turn_profile' => TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]),
    ]);

    Assert::same(
        20_000,
        $provider->completionBudget,
        'an existing-page overhaul should fit a full preserved document in the proposal call',
    );
    Assert::same('low', $provider->reasoningEffort, 'structured page output should prioritize emitting the payload');
}

test_provider_runtime_gives_existing_page_composition_room_for_preserved_copy();

function test_provider_runtime_reviews_only_genuinely_rendered_preview_evidence(): void {
    $runtime = new ProviderRuntime();
    $method = new ReflectionMethod(ProviderRuntime::class, 'should_review_visual_evidence');
    $static_fallback = [
        'tool_call' => ['output' => ['rendered' => false]],
        'message' => ['role' => 'user', 'content' => 'Static fallback only.'],
    ];
    $rendered = [
        'tool_call' => ['output' => ['rendered' => true]],
        'message' => ['role' => 'user', 'content' => 'Rendered evidence.'],
    ];

    Assert::false(
        $method->invoke($runtime, $static_fallback, 90, 2, 6),
        'static fallback inspection must not consume another provider completion',
    );
    $semantic_fallback = [
        'tool_call' => [
            'output' => [
                'rendered' => false,
                'main_h1_count' => 0,
                'main_heading_outline' => [['level' => 2, 'text' => 'Filing Basics']],
            ],
        ],
        'message' => ['role' => 'user', 'content' => 'Static semantic evidence.'],
    ];
    Assert::true(
        $method->invoke($runtime, $semantic_fallback, 90, 2, 6, true),
        'presentation edits should review authoritative static heading evidence',
    );
    Assert::false(
        $method->invoke($runtime, $semantic_fallback, 90, 2, 6, false),
        'ordinary proposals should not spend a review round on static fallback alone',
    );
    Assert::true(
        $method->invoke($runtime, $rendered, 90, 2, 6),
        'genuine rendered evidence may receive one bounded model review',
    );
}

test_provider_runtime_reviews_only_genuinely_rendered_preview_evidence();

function test_provider_runtime_keeps_focused_edits_out_of_patterned_post_finalization(): void {
    $runtime = new ProviderRuntime();
    $method = new ReflectionMethod(ProviderRuntime::class, 'compose_abilities_for');
    $profile = AWPT\Agent\TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]);
    $abilities = $method->invoke(
        $runtime,
        [[
            'tool' => 'awpt/prepare-pattern-draft',
            'status' => 'success',
            'output' => ['mode' => 'pattern'],
        ]],
        $profile,
        ['awpt/propose-patterned-post'],
    );

    Assert::true(
        in_array('awpt/propose-block-batch-update', $abilities, true),
        'focused presentation work should retain the atomic existing-page proposal tool',
    );
    Assert::false(
        in_array('awpt/propose-patterned-post', $abilities, true),
        'new-post pattern preparation must not replace focused edit tools',
    );
}

function test_provider_runtime_exposes_an_actionable_failed_turn_outcome(): void {
    $runtime = new ProviderRuntime();
    $method = new ReflectionMethod(ProviderRuntime::class, 'turn_outcome');
    $failed = $method->invoke(
        $runtime,
        [[
            'tool' => 'awpt/propose-block-batch-update',
            'status' => 'failed',
            'output' => [
                'error_code' => 'awpt_block_fingerprint_mismatch',
                'error' => 'A target changed since it was inspected.',
            ],
        ]],
        [],
        '',
    );

    Assert::same('failed', $failed['status'] ?? '', 'proposal failures must not look like successful no-op turns');
    Assert::same(
        'awpt_block_fingerprint_mismatch',
        $failed['error_code'] ?? '',
        'the review surface should receive the concrete retry reason',
    );
}

test_provider_runtime_keeps_focused_edits_out_of_patterned_post_finalization();

function test_provider_runtime_presentation_compose_prefers_pattern_tools_after_recommendations(): void {
    $runtime = new ProviderRuntime();
    $method = new ReflectionMethod(ProviderRuntime::class, 'compose_abilities_for');
    $profile = AWPT\Agent\TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]);
    $pack = [
        'coverage' => ['page_analysis', 'rendered_inspection', 'pattern_consulted', 'pattern_recommendation', 'pattern_structure'],
        'content_reads' => [[
            'tool' => 'awpt/read-block-tree',
            'output' => [
                'blocks' => [['path' => '0', 'name' => 'core/heading', 'fingerprint' => str_repeat('a', 64)]],
            ],
        ]],
    ];
    $abilities = $method->invoke(
        $runtime,
        [],
        $profile,
        [
            'awpt/propose-content-update',
            'awpt/propose-block-batch-update',
            'awpt/propose-pattern-insert',
            'awpt/propose-block-attrs-update',
        ],
        $pack,
        true,
    );

    // Pattern-first is advisory: redesign compose keeps the full proposal surface when fingerprints exist.
    Assert::true(in_array('awpt/propose-pattern-insert', $abilities, true), 'pattern insert should stay available');
    Assert::true(in_array('awpt/propose-content-update', $abilities, true), 'pattern-backed content update should stay available');
    Assert::true(
        in_array('awpt/propose-block-batch-update', $abilities, true),
        'surgical batch tools remain available without unfit ceremony',
    );
    Assert::true(
        in_array('awpt/propose-block-attrs-update', $abilities, true),
        'attrs surgical tools remain available without unfit ceremony',
    );
}

test_provider_runtime_presentation_compose_prefers_pattern_tools_after_recommendations();

function test_provider_runtime_narrows_compose_tools_without_fingerprints(): void {
    $runtime = new ProviderRuntime();
    $method = new ReflectionMethod(ProviderRuntime::class, 'compose_abilities_for');
    $profile = AWPT\Agent\TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]);
    $empty_pack = [
        'content_reads' => [],
        'page_brief' => ['main_h1_count' => 0],
    ];
    $abilities = $method->invoke(
        $runtime,
        [],
        $profile,
        ['awpt/propose-content-update', 'awpt/propose-block-batch-update'],
        $empty_pack,
    );

    Assert::same(
        ['awpt/propose-content-update'],
        $abilities,
        'without fingerprints compose must not offer batch/attrs tools',
    );

    $fp = hash('sha256', 'ok');
    $with_tree = [
        'content_reads' => [[
            'tool' => 'awpt/read-block-tree',
            'output' => [
                'blocks' => [['path' => '0', 'name' => 'core/paragraph', 'fingerprint' => $fp]],
            ],
        ]],
    ];
    $full = $method->invoke(
        $runtime,
        [],
        $profile,
        ['awpt/propose-content-update', 'awpt/propose-block-batch-update'],
        $with_tree,
    );
    Assert::true(
        in_array('awpt/propose-block-batch-update', $full, true),
        'fingerprint-bearing packs keep surgical compose tools',
    );
}

test_provider_runtime_narrows_compose_tools_without_fingerprints();

function test_provider_runtime_drops_same_turn_actions_superseded_by_a_revision(): void {
    $method = new ReflectionMethod(ProviderRuntime::class, 'merge_actions');
    $actions = $method->invoke(
        new ProviderRuntime(),
        [['id' => 10, 'status' => 'proposed']],
        [['id' => 11, 'status' => 'proposed', 'removed_action_ids' => [10]]],
    );

    Assert::same([11], array_column($actions, 'id'), 'auto-apply should receive only the live corrected action');
}

test_provider_runtime_drops_same_turn_actions_superseded_by_a_revision();
test_provider_runtime_exposes_an_actionable_failed_turn_outcome();

final class AwptFinalizationFailureProvider implements ProviderInterface {
    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools, $options);

        return new WP_Error('http_request_failed', 'The provider request timed out.');
    }

    public function get_name(): string {
        return 'Finalization failure test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_exposes_provider_finalization_failures_to_review_surfaces(): void {
    awpt_test_reset_state();
    $method = new ReflectionMethod(ProviderRuntime::class, 'follow_up_round');
    $result = $method->invoke(new ProviderRuntime(), new AwptFinalizationFailureProvider(), new ToolRegistry(), [
        'session_id' => 1,
        'messages' => [['role' => 'user', 'content' => 'Make this page more presentable.']],
        'tool_calls' => [],
        'result' => [],
        'content' => '',
        'turn_started_at' => microtime(true),
        'turn_wall_seconds' => 240,
        'compose_only' => true,
        'turn_profile' => TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]),
        'finalization_retry' => true,
    ]);

    Assert::same(
        'failed',
        $result['failure_tool_call']['status'] ?? '',
        'provider timeouts must be structured failures',
    );
    Assert::same(
        'http_request_failed',
        $result['failure_tool_call']['output']['error_code'] ?? '',
        'review clients should receive the concrete provider error code',
    );
    Assert::true(
        ToolRegistry::is_proposal_ability((string) ($result['failure_tool_call']['tool'] ?? '')),
        'the failure must be attached to a proposal ability so turn_outcome classifies it as failed',
    );
}

test_provider_runtime_exposes_provider_finalization_failures_to_review_surfaces();

function test_provider_runtime_detects_unrestricted_custom_fallback_turns(): void {
    $runtime = new ProviderRuntime();
    $method = new ReflectionMethod(ProviderRuntime::class, 'has_custom_fallback');

    Assert::true($method->invoke($runtime, [[
        'tool' => 'awpt/prepare-pattern-draft',
        'status' => 'success',
        'output' => ['mode' => 'custom_fallback'],
    ]]), 'explicit from-scratch preparation should activate the larger raw-composition transport envelope');
    Assert::false($method->invoke($runtime, [[
        'tool' => 'awpt/prepare-pattern-draft',
        'status' => 'success',
        'output' => ['mode' => 'pattern'],
    ]]), 'ordinary pattern composition should retain the compact transport budget');
}

test_provider_runtime_detects_unrestricted_custom_fallback_turns();

final class AwptRecoveryStallTestProvider implements ProviderInterface {
    public int $completions = 0;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($tools, $options);
        ++$this->completions;

        if (1 === $this->completions) {
            return [
                'content' => 'Please run the suggested tools yourself.',
                'raw_tool_calls' => [],
                'message' => ['role' => 'assistant', 'content' => 'Please run the suggested tools yourself.'],
                'model' => 'fake',
                'usage' => [],
            ];
        }

        if (2 === $this->completions) {
            $saw_nudge = false;

            foreach ($messages as $message) {
                if (str_contains((string) ($message['content'] ?? ''), 'still unresolved')) {
                    $saw_nudge = true;
                }
            }

            Assert::true($saw_nudge, 'a stalled recovery should receive a grounded continuation instruction');

            return $this->tool_result('call-read', 'demo__read');
        }

        return $this->tool_result('call-corrected', 'awpt__propose_new_post');
    }

    public function get_name(): string {
        return 'Recovery stall test';
    }

    public function accepts_image_input(): bool {
        return false;
    }

    /** @return array<string, mixed> */
    private function tool_result(string $id, string $function): array {
        $calls = [[
            'id' => $id,
            'function' => ['name' => $function, 'arguments' => '{}'],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ];
    }
}

function test_provider_runtime_continues_after_proposal_recovery_stalls_in_prose(): void {
    awpt_test_reset_state();
    add_filter('awpt_mcp_tools', static fn(): array => [
        [
            'name' => 'awpt/propose-new-post',
            'description' => 'Stage a test proposal.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
        ['name' => 'demo/read', 'description' => 'Read recovery evidence.', 'readonly' => true],
    ]);
    $proposal_calls = 0;
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name) use (&$proposal_calls): array|WP_Error {
            unset($result);

            if ('awpt/propose-new-post' !== $tool_name) {
                return ['ok' => true];
            }

            ++$proposal_calls;

            if (1 === $proposal_calls) {
                return new WP_Error('awpt_pattern_not_found', 'Pattern unavailable.', [
                    'available_patterns' => [['name' => 'civicpress/header-hero']],
                    'recommended_next_tools' => [['tool' => 'demo/read', 'input' => []]],
                ]);
            }

            return ['id' => 28, 'title' => 'Recovered proposal', 'status' => 'proposed'];
        },
        10,
        2,
    );
    $provider = new AwptRecoveryStallTestProvider();
    $initial_calls = [[
        'id' => 'call-initial',
        'function' => ['name' => 'awpt__propose_new_post', 'arguments' => '{}'],
    ]];
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Create the page.']],
        [
            'content' => '',
            'raw_tool_calls' => $initial_calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $initial_calls],
            'model' => 'fake',
            'usage' => [],
        ],
        ['tool_registry' => new ToolRegistry()],
    );

    Assert::same(3, $provider->completions, 'the stalled prose should not consume the remaining recovery path');
    Assert::same(3, count($result['tool_calls']), 'the failed proposal, recovery read, and corrected proposal survive');
    Assert::same(1, count($result['actions']), 'the corrected successful proposal should become an action');
}

test_provider_runtime_continues_after_proposal_recovery_stalls_in_prose();

/**
 * After content-loss recovery enters compose, a stalled/502 follow-up must keep
 * compose proposal tools offered — not demote to explore.
 */
final class AwptComposeRecoveryStallProvider implements ProviderInterface {
    public int $completions = 0;

    /** @var list<list<string>> */
    public array $offered_by_completion = [];

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($options);
        ++$this->completions;
        $names = array_values(array_filter(array_map(
            static fn(array $tool): string => (string) ($tool['function']['name'] ?? ''),
            $tools,
        )));
        $this->offered_by_completion[] = $names;

        if (1 === $this->completions) {
            // Simulate provider 502 / stall after validation failure — no tools.
            return [
                'content' => 'I will refine the proposal next.',
                'raw_tool_calls' => [],
                'message' => ['role' => 'assistant', 'content' => 'I will refine the proposal next.'],
                'model' => 'fake',
                'usage' => [],
            ];
        }

        Assert::true(
            in_array('awpt__propose_block_batch_update', $names, true)
            || in_array('wpab__awpt__propose-block-batch-update', $names, true)
            || in_array('awpt/propose-block-batch-update', $names, true)
            || [] !== array_filter($names, static fn(string $n): bool => str_contains($n, 'propose_block_batch') || str_contains($n, 'propose-block-batch')),
            'stall nudge after compose recovery must keep proposal tools offered; got: ' . implode(',', $names),
        );

        $calls = [[
            'id' => 'compose-stall-corrected',
            'function' => [
                'name' => 'awpt__propose_block_batch_update',
                'arguments' => '{"post_id":550,"title":"Corrected batch","changes":[]}',
            ],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ];
    }

    public function get_name(): string {
        return 'Compose recovery stall test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_keeps_compose_tools_after_recovery_stall(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 550;
    $post->post_title = 'SLIP';
    $post->post_content = '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';
    $GLOBALS['awpt_test_posts'][550] = $post;
    add_filter('awpt_mcp_tools', static fn(): array => [
        [
            'name' => 'awpt/propose-content-update',
            'description' => 'Stage complete content.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
        [
            'name' => 'awpt/propose-block-batch-update',
            'description' => 'Stage targeted block changes.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
        [
            'name' => 'awpt/analyze-page',
            'description' => 'Explore evidence.',
            'readonly' => true,
            'destructive' => false,
            'requires_approval' => false,
        ],
    ]);
    $proposal_attempts = 0;
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name) use (&$proposal_attempts): array|WP_Error {
            unset($result);

            if ('awpt/propose-content-update' === $tool_name) {
                ++$proposal_attempts;

                return new WP_Error('awpt_presentation_content_loss', 'Preserve the source page.', [
                    'token_recall' => 0.786,
                    'missing_excerpt' => 'Legislative Chair',
                ]);
            }

            if ('awpt/propose-block-batch-update' === $tool_name) {
                ++$proposal_attempts;

                return [
                    'id' => 97,
                    'title' => 'Corrected batch',
                    'status' => 'proposed',
                    'payload' => ['operation' => 'block_batch_update', 'post_id' => 550],
                ];
            }

            return ['ok' => true];
        },
        10,
        2,
    );

    $initial_calls = [[
        'id' => 'compose-stall-initial',
        'function' => [
            'name' => 'awpt__propose_content_update',
            'arguments' => '{"post_id":550,"title":"Presentation rewrite"}',
        ],
    ]];
    $provider = new AwptComposeRecoveryStallProvider();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Make this page more presentable.']],
        [
            'content' => '',
            'raw_tool_calls' => $initial_calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $initial_calls],
            'model' => 'fake',
            'usage' => [],
        ],
        [
            'tool_registry' => new ToolRegistry(),
            'is_content_edit_turn' => true,
            'uses_explore_compose' => true,
            'presentation_edit' => true,
            'turn_profile' => TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]),
            'compose_abilities' => ['awpt/propose-content-update', 'awpt/propose-block-batch-update'],
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(2, $provider->completions, 'stall prose then corrected proposal');
    Assert::same(1, count($result['actions']), 'corrected batch proposal should stage after stall nudge');
    $stall_tools = $provider->offered_by_completion[1] ?? [];
    $has_propose = [] !== array_filter(
        $stall_tools,
        static fn(string $n): bool => str_contains($n, 'propose'),
    );
    Assert::true($has_propose, 'second completion after stall must offer propose tools, got: ' . implode(',', $stall_tools));
}

test_provider_runtime_keeps_compose_tools_after_recovery_stall();

final class AwptFailedFollowUpProvider implements ProviderInterface {
    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools, $options);

        return new WP_Error('http_request_failed', 'Request timed out.');
    }

    public function get_name(): string {
        return 'Failed follow-up test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_formats_discovery_only_once_after_follow_up_failure(): void {
    awpt_test_reset_state();
    add_filter('awpt_mcp_tools', static fn(): array => [[
        'name' => 'demo/read',
        'description' => 'Read evidence.',
        'readonly' => true,
    ]]);
    add_filter('awpt_mcp_execute_tool', static fn(): array => ['evidence' => 'complete']);
    $calls = [[
        'id' => 'call-read-once',
        'function' => ['name' => 'demo__read', 'arguments' => '{}'],
    ]];
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        new AwptFailedFollowUpProvider(),
        [['role' => 'user', 'content' => 'Generate a landing page.']],
        [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ],
        ['tool_registry' => new ToolRegistry()],
    );

    Assert::true(
        str_contains((string) $result['content'], 'Request timed out'),
        'admins should retain the provider detail for troubleshooting',
    );
    Assert::true(
        str_contains((string) $result['content'], 'no change was staged'),
        'the timeout fallback should state the outcome clearly',
    );
    Assert::false(
        str_contains((string) $result['content'], 'Tool demo/read returned'),
        'the timeout fallback should not replay raw tool output',
    );
}

test_provider_runtime_formats_discovery_only_once_after_follow_up_failure();

final class AwptNoFollowUpAfterProposalProvider implements ProviderInterface {
    public int $completions = 0;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools, $options);
        ++$this->completions;

        return new WP_Error('unexpected_follow_up', 'A successful proposal must end the loop.');
    }

    public function get_name(): string {
        return 'Proposal terminal-state test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_stops_after_successful_content_update_proposal(): void {
    awpt_test_reset_state();
    add_filter('awpt_mcp_tools', static fn(): array => [[
        'name' => 'awpt/propose-content-update',
        'description' => 'Stage a content update.',
        'readonly' => false,
        'destructive' => false,
        'requires_approval' => true,
    ]]);
    add_filter('awpt_mcp_execute_tool', static fn(): array => [
        'id' => 42,
        'title' => 'Format Stamping Fee as documentation',
        'status' => 'proposed',
        'payload' => ['operation' => 'content_update', 'post_id' => 408],
    ]);
    $calls = [[
        'id' => 'call-content-update',
        'function' => [
            'name' => 'awpt__propose_content_update',
            'arguments' => '{"post_id":408}',
        ],
    ]];
    $provider = new AwptNoFollowUpAfterProposalProvider();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Make page 408 a documentation page.']],
        [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ],
        ['tool_registry' => new ToolRegistry()],
    );

    Assert::same(0, $provider->completions, 'a successful content-update proposal should not trigger follow-up');
    Assert::same(1, count($result['actions']), 'the staged content update should be returned as an action');
    Assert::true(str_contains($result['content'], 'staged action #42'), 'the reply should confirm the staged action');
}

test_provider_runtime_stops_after_successful_content_update_proposal();

final class AwptCompositionGateProvider implements ProviderInterface {
    public int $completions = 0;
    public bool $timeout_first = false;
    public bool $timeout_always = false;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        ++$this->completions;
        Assert::true([] !== $tools, 'composition phase should expose available proposal tools');
        Assert::same(
            [
                'type' => 'function',
                'function' => ['name' => 'awpt__propose_new_post'],
            ],
            $options['tool_choice'] ?? null,
            'new-page finalization should require the exact new-post proposal function',
        );
        Assert::same(
            4_800,
            (int) ($options['max_completion_tokens'] ?? 0),
            'proposal-only finalization should use the bounded composition budget',
        );

        if ($this->timeout_always || $this->timeout_first && 1 === $this->completions) {
            return new WP_Error('http_request_failed', 'Operation timed out.');
        }

        if ($this->timeout_first) {
            Assert::same(3, count($messages), 'timeout retry should use compact evidence messages');
        }

        $calls = [[
            'id' => 'call-proposal-' . $this->completions,
            'function' => ['name' => 'awpt__propose_new_post', 'arguments' => '{}'],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ];
    }

    public function get_name(): string {
        return 'Composition gate test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

/** @return array<string, mixed> */
function awpt_discovery_result_for_runtime(): array {
    $calls = [
        [
            'id' => 'call-patterns',
            'function' => ['name' => 'awpt__list_patterns', 'arguments' => '{"post_type":"page"}'],
        ],
        [
            'id' => 'call-media',
            'function' => [
                'name' => 'awpt__list_content',
                'arguments' => '{"post_type":"attachment","limit":8}',
            ],
        ],
        [
            'id' => 'call-layout',
            'function' => [
                'name' => 'awpt__read_pattern',
                'arguments' => '{"name":"civicpress/layout-page-landing-page","purpose":"Establish the page structure"}',
            ],
        ],
        [
            'id' => 'call-hero',
            'function' => [
                'name' => 'awpt__read_pattern',
                'arguments' => '{"name":"civicpress/header-hero","purpose":"Adapt the hero"}',
            ],
        ],
        [
            'id' => 'call-tagline',
            'function' => [
                'name' => 'awpt__read_pattern',
                'arguments' => '{"name":"civicpress/section-tagline","purpose":"Adapt the tagline"}',
            ],
        ],
        [
            'id' => 'call-list',
            'function' => [
                'name' => 'awpt__read_pattern',
                'arguments' => '{"name":"civicpress/section-graphic-list","purpose":"Adapt league highlights"}',
            ],
        ],
        [
            'id' => 'call-cta',
            'function' => [
                'name' => 'awpt__read_pattern',
                'arguments' => '{"name":"civicpress/section-call-to-action","purpose":"Adapt ticket CTA"}',
            ],
        ],
    ];

    return [
        'content' => '',
        'raw_tool_calls' => $calls,
        'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
        'model' => 'fake',
        'usage' => [],
    ];
}

function awpt_register_composition_gate_tools(): ToolRegistry {
    add_filter('awpt_mcp_tools', static fn(): array => [
        ['name' => 'awpt/list-patterns', 'description' => 'List patterns.', 'readonly' => true],
        ['name' => 'awpt/list-content', 'description' => 'List media.', 'readonly' => true],
        ['name' => 'awpt/read-pattern', 'description' => 'Read pattern.', 'readonly' => true],
        [
            'name' => 'awpt/propose-new-post',
            'description' => 'Stage proposal.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
    ]);
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name): array {
            unset($result);

            return match ($tool_name) {
                'awpt/list-patterns' => ['patterns' => [['name' => 'civicpress/layout-page-landing-page']]],
                'awpt/list-content' => ['items' => [['id' => 128, 'type' => 'attachment']]],
                'awpt/read-pattern' => [
                    'name' => 'civicpress/layout-page-landing-page',
                    'composition_scope' => 'layout',
                    'design_dependencies' => ['requires_theme_research' => true],
                    'content' => '<!-- wp:group /-->',
                ],
                default => ['id' => 91, 'title' => 'Staged landing page', 'status' => 'proposed'],
            };
        },
        10,
        2,
    );

    return new ToolRegistry();
}

final class AwptTruncatedCompositionProvider implements ProviderInterface {
    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools, $options);

        $calls = [[
            'id' => 'truncated-proposal-call',
            'function' => [
                'name' => 'awpt__propose_new_post',
                'arguments' => '{"post_title":"Partial","post_content":"<!-- wp:group --><div>Cut off"}',
            ],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'finish_reason' => 'length',
            'model' => 'fake',
            'usage' => [],
        ];
    }

    public function get_name(): string {
        return 'Truncated composition test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_reports_truncated_proposal_as_failure(): void {
    awpt_test_reset_state();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        new AwptTruncatedCompositionProvider(),
        [['role' => 'user', 'content' => 'Create a page using images from my media library.']],
        awpt_discovery_result_for_runtime(),
        [
            'tool_registry' => awpt_register_composition_gate_tools(),
            'is_content_turn' => true,
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );
    $last_call = end($result['tool_calls']);

    Assert::same(0, count($result['actions']), 'a truncated tool payload must not stage a partial action');
    Assert::same('failed', $last_call['status'] ?? '', 'truncation should be represented as a failed proposal');
    Assert::same(
        'awpt_proposal_output_truncated',
        $last_call['output']['error_code'] ?? '',
        'the review surface should receive an actionable truncation code',
    );
    Assert::true(
        str_contains($result['content'], 'No change was applied'),
        'the transcript should never present truncated discovery output as a completed review',
    );
}

test_provider_runtime_reports_truncated_proposal_as_failure();

function test_provider_runtime_forces_proposal_after_sufficient_discovery(): void {
    awpt_test_reset_state();
    $provider = new AwptCompositionGateProvider();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Create a page using images from my media library.']],
        awpt_discovery_result_for_runtime(),
        [
            'tool_registry' => awpt_register_composition_gate_tools(),
            'is_content_turn' => true,
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(1, $provider->completions, 'sufficient discovery should immediately enter composition');
    Assert::same(1, count($result['actions']), 'proposal-only completion should stage an action');
}

function test_provider_runtime_retries_timed_out_finalization_once_with_compact_evidence(): void {
    awpt_test_reset_state();
    $provider = new AwptCompositionGateProvider();
    $provider->timeout_first = true;
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Create a page using images from my media library.']],
        awpt_discovery_result_for_runtime(),
        [
            'tool_registry' => awpt_register_composition_gate_tools(),
            'is_content_turn' => true,
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(2, $provider->completions, 'finalization timeout should retry exactly once');
    Assert::same(1, count($result['actions']), 'successful retry should stage the proposal');
}

final class AwptCompositionRecoveryProvider implements ProviderInterface {
    public int $completions = 0;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages);
        ++$this->completions;

        Assert::same(
            [
                'type' => 'function',
                'function' => ['name' => 'awpt__propose_new_post'],
            ],
            $options['tool_choice'] ?? null,
            'a corrected proposal must retain the exact new-post staging function',
        );
        Assert::true([] !== $tools, 'a corrected proposal must retain proposal tools');

        $calls = [[
            'id' => 'call-corrected-' . $this->completions,
            'function' => ['name' => 'awpt__propose_new_post', 'arguments' => '{}'],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ];
    }

    public function get_name(): string {
        return 'Composition recovery test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_keeps_proposal_tool_after_validation_failure(): void {
    awpt_test_reset_state();
    $attempts = 0;
    add_filter('awpt_mcp_tools', static fn(): array => [
        ['name' => 'awpt/list-patterns', 'description' => 'List patterns.', 'readonly' => true],
        ['name' => 'awpt/list-content', 'description' => 'List media.', 'readonly' => true],
        ['name' => 'awpt/read-pattern', 'description' => 'Read pattern.', 'readonly' => true],
        [
            'name' => 'awpt/propose-new-post',
            'description' => 'Stage proposal.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
    ]);
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name) use (&$attempts): array|WP_Error {
            unset($result);

            if ('awpt/propose-new-post' !== $tool_name) {
                return match ($tool_name) {
                    'awpt/list-patterns' => ['patterns' => [['name' => 'civicpress/layout-page-landing-page']]],
                    'awpt/list-content' => ['items' => [['id' => 128, 'type' => 'attachment']]],
                    default => [
                        'name' => 'civicpress/layout-page-landing-page',
                        'composition_scope' => 'layout',
                        'content' => '<!-- wp:group /-->',
                    ],
                };
            }

            ++$attempts;

            if ($attempts <= 2) {
                return new WP_Error('awpt_invalid_composition', 'The active composition rules rejected this proposal.');
            }

            return ['id' => 92, 'title' => 'Corrected landing page', 'status' => 'proposed'];
        },
        10,
        2,
    );
    $provider = new AwptCompositionRecoveryProvider();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Create a page using images from my media library.']],
        awpt_discovery_result_for_runtime(),
        [
            'tool_registry' => new ToolRegistry(),
            'is_content_turn' => true,
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(3, $provider->completions, 'two bounded validation corrections should receive real proposal retries');
    Assert::same(1, count($result['actions']), 'the final corrected proposal should stage successfully');
}

final class AwptPatternReadRecoveryProvider implements ProviderInterface {
    public int $completions = 0;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $options);
        ++$this->completions;
        $names = array_map(static fn(array $tool): string => (string) ($tool['function']['name'] ?? ''), $tools);

        if (1 === $this->completions) {
            Assert::true(
                in_array('awpt__read_pattern', $names, true),
                'pattern validation recovery must expose the requested read tool',
            );

            return $this->tool_result('awpt__read_pattern', '{"name":"civicpress/layout-page-documentation"}');
        }

        Assert::true(
            in_array('awpt__propose_content_update', $names, true),
            'a successful pattern read should return directly to proposal-only tools',
        );

        return $this->tool_result('awpt__propose_content_update', '{"post_id":580}');
    }

    public function get_name(): string {
        return 'Pattern read recovery test';
    }

    public function accepts_image_input(): bool {
        return false;
    }

    /** @return array<string, mixed> */
    private function tool_result(string $name, string $arguments): array {
        $calls = [[
            'id' => 'pattern-recovery-' . $this->completions,
            'function' => ['name' => $name, 'arguments' => $arguments],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ];
    }
}

function test_provider_runtime_reads_missing_pattern_before_retrying_proposal(): void {
    awpt_test_reset_state();
    add_filter('awpt_mcp_tools', static fn(): array => [
        ['name' => 'awpt/read-pattern', 'description' => 'Read pattern.', 'readonly' => true],
        [
            'name' => 'awpt/propose-content-update',
            'description' => 'Stage content.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
    ]);
    $proposal_attempts = 0;
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name) use (&$proposal_attempts): array|WP_Error {
            unset($result);

            if ('awpt/read-pattern' === $tool_name) {
                return [
                    'name' => 'civicpress/layout-page-documentation',
                    'composition_scope' => 'layout',
                    'content' => '<!-- wp:group /-->',
                ];
            }

            ++$proposal_attempts;

            if (1 === $proposal_attempts) {
                return new WP_Error('awpt_pattern_not_read', 'Read the selected pattern first.', [
                    'recommended_next_tools' => [[
                        'tool' => 'awpt/read-pattern',
                        'input' => ['name' => 'civicpress/layout-page-documentation'],
                    ]],
                ]);
            }

            return [
                'id' => 94,
                'title' => 'Corrected documentation layout',
                'status' => 'proposed',
                'payload' => ['operation' => 'content_update', 'post_id' => 580],
            ];
        },
        10,
        2,
    );
    $initial_calls = [[
        'id' => 'pattern-recovery-initial',
        'function' => [
            'name' => 'awpt__propose_content_update',
            'arguments' => '{"post_id":580,"pattern_name":"civicpress/layout-page-documentation"}',
        ],
    ]];
    $initial = [
        'content' => '',
        'raw_tool_calls' => $initial_calls,
        'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $initial_calls],
        'model' => 'fake',
        'usage' => [],
    ];
    $provider = new AwptPatternReadRecoveryProvider();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Make this page more presentable.']],
        $initial,
        [
            'tool_registry' => new ToolRegistry(),
            'is_content_edit_turn' => true,
            'uses_explore_compose' => true,
            'turn_profile' => TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]),
            'compose_abilities' => ['awpt/propose-content-update'],
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(2, $provider->completions, 'recovery should use one read turn and one corrected proposal turn');
    Assert::same(1, count($result['actions']), 'the corrected pattern-backed proposal should stage');
}

test_provider_runtime_reads_missing_pattern_before_retrying_proposal();

final class AwptPresentationLossRecoveryProvider implements ProviderInterface {
    public int $completions = 0;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($options);
        ++$this->completions;
        $names = array_map(static fn(array $tool): string => (string) ($tool['function']['name'] ?? ''), $tools);
        $system = implode("\n", array_map(static fn(array $message): string => 'system' === ($message['role'] ?? '')
            ? (string) ($message['content'] ?? '')
            : '', $messages));

        Assert::true(
            in_array('awpt__propose_content_update', $names, true),
            'content-loss recovery should retain the full-page correction tool',
        );
        Assert::true(
            in_array('awpt__propose_block_batch_update', $names, true),
            'content-loss recovery should retain the targeted alternative',
        );
        Assert::true(
            str_contains($system, 'may retry a full-document layout adaptation'),
            'content-loss recovery should allow a corrected overhaul when it remains appropriate',
        );
        Assert::false(
            str_contains($system, 'Do not submit another full-document rewrite'),
            'content-loss recovery must not collapse every overhaul into cosmetic block edits',
        );

        $calls = [[
            'id' => 'presentation-loss-recovery',
            'function' => [
                'name' => 'awpt__propose_content_update',
                'arguments' => '{"post_id":580,"title":"Corrected documentation layout"}',
            ],
        ]];

        return [
            'content' => '',
            'raw_tool_calls' => $calls,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls],
            'model' => 'fake',
            'usage' => [],
        ];
    }

    public function get_name(): string {
        return 'Presentation content-loss recovery test';
    }

    public function accepts_image_input(): bool {
        return false;
    }
}

function test_provider_runtime_allows_corrected_full_page_retry_after_content_loss(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 580;
    $post->post_title = 'Docs';
    $post->post_content = '<!-- wp:paragraph --><p>Statute 1774</p><!-- /wp:paragraph -->';
    $GLOBALS['awpt_test_posts'][580] = $post;
    add_filter('awpt_mcp_tools', static fn(): array => [
        [
            'name' => 'awpt/propose-content-update',
            'description' => 'Stage complete content.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
        [
            'name' => 'awpt/propose-block-batch-update',
            'description' => 'Stage targeted block changes.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
    ]);
    $proposal_attempts = 0;
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name) use (&$proposal_attempts): array|WP_Error {
            unset($result);
            ++$proposal_attempts;

            if (1 === $proposal_attempts) {
                return new WP_Error('awpt_presentation_content_loss', 'Preserve the source page.', [
                    'missing_links' => ['https://example.com/statute'],
                    'missing_numeric_tokens' => ['1774'],
                ]);
            }

            return [
                'id' => 96,
                'title' => 'Corrected documentation layout',
                'status' => 'proposed',
                'payload' => ['operation' => 'content_update', 'post_id' => 580],
            ];
        },
        10,
        2,
    );
    $initial_calls = [[
        'id' => 'presentation-loss-initial',
        'function' => [
            'name' => 'awpt__propose_content_update',
            'arguments' => '{"post_id":580,"title":"Documentation layout"}',
        ],
    ]];
    $initial = [
        'content' => '',
        'raw_tool_calls' => $initial_calls,
        'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $initial_calls],
        'model' => 'fake',
        'usage' => [],
    ];
    $provider = new AwptPresentationLossRecoveryProvider();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Make this page more presentable.']],
        $initial,
        [
            'tool_registry' => new ToolRegistry(),
            'is_content_edit_turn' => true,
            'uses_explore_compose' => true,
            'turn_profile' => TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]),
            'compose_abilities' => ['awpt/propose-content-update', 'awpt/propose-block-batch-update'],
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(1, $provider->completions, 'the validator should receive one corrected full-page retry');
    Assert::same(1, count($result['actions']), 'the corrected full-page proposal should stage');
}

test_provider_runtime_allows_corrected_full_page_retry_after_content_loss();

function test_provider_runtime_allows_one_terminal_content_loss_recovery(): void {
    $method = new ReflectionMethod(ProviderRuntime::class, 'should_allow_terminal_content_loss_recovery');
    $runtime = new ProviderRuntime();

    Assert::true(
        $method->invoke($runtime, 3, 'awpt_presentation_content_loss', false),
        'a content-loss diagnosis at the ordinary failure boundary should earn one block-level recovery',
    );
    Assert::false(
        $method->invoke($runtime, 4, 'awpt_presentation_content_loss', true),
        'the terminal recovery must remain bounded even if its replacement proposal also loses content',
    );
    Assert::false(
        $method->invoke($runtime, 3, 'awpt_domain_validation_failed', false),
        'unrelated validation failures should retain the ordinary correction cap',
    );
}

test_provider_runtime_allows_one_terminal_content_loss_recovery();

function test_provider_runtime_allows_one_terminal_pattern_name_recovery(): void {
    $method = new ReflectionMethod(ProviderRuntime::class, 'should_allow_terminal_pattern_recovery');
    $runtime = new ProviderRuntime();

    Assert::true(
        $method->invoke($runtime, 3, 'awpt_pattern_not_found', false),
        'an invented pattern at the ordinary failure boundary should earn one exact-name correction',
    );
    Assert::false(
        $method->invoke($runtime, 4, 'awpt_pattern_not_found', true),
        'terminal pattern-name recovery must remain bounded',
    );
    Assert::false(
        $method->invoke($runtime, 3, 'awpt_required_page_h1_missing', false),
        'unrelated failures should not receive pattern-name recovery',
    );
}

test_provider_runtime_allows_one_terminal_pattern_name_recovery();

function test_provider_runtime_allows_one_terminal_required_h1_recovery(): void {
    $method = new ReflectionMethod(ProviderRuntime::class, 'should_allow_terminal_heading_recovery');
    $runtime = new ProviderRuntime();

    Assert::true(
        $method->invoke($runtime, 3, 'awpt_required_page_h1_missing', false),
        'a missing required H1 at the ordinary failure boundary should earn one atomic correction',
    );
    Assert::false(
        $method->invoke($runtime, 4, 'awpt_required_page_h1_missing', true),
        'terminal heading recovery must remain bounded',
    );
    Assert::false(
        $method->invoke($runtime, 3, 'awpt_pattern_not_found', false),
        'unrelated failures should not receive required-H1 recovery',
    );
    Assert::true(
        $method->invoke($runtime, 3, 'awpt_heading_level_skipped', false),
        'a skipped heading level should use the same bounded outline recovery',
    );
}

test_provider_runtime_allows_one_terminal_required_h1_recovery();

function test_provider_runtime_explains_failed_finalization_with_turn_diagnostics(): void {
    awpt_test_reset_state();
    $provider = new AwptCompositionGateProvider();
    $provider->timeout_always = true;
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Create a page using images from my media library.']],
        awpt_discovery_result_for_runtime(),
        [
            'tool_registry' => awpt_register_composition_gate_tools(),
            'is_content_turn' => true,
            'turn_started_at' => microtime(true),
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(2, $provider->completions, 'failed finalization should make only one compact retry');
    Assert::true(
        str_contains($result['content'], 'no change was staged'),
        'failure should state that the requested mutation did not occur',
    );
    Assert::true(
        str_contains($result['content'], 'Operation timed out'),
        'admins should still receive the provider detail',
    );
}

test_provider_runtime_forces_proposal_after_sufficient_discovery();
test_provider_runtime_retries_timed_out_finalization_once_with_compact_evidence();
test_provider_runtime_keeps_proposal_tool_after_validation_failure();
test_provider_runtime_explains_failed_finalization_with_turn_diagnostics();

function test_provider_runtime_skips_doomed_follow_up_when_wall_nearly_gone(): void {
    awpt_test_reset_state();
    $provider = new AwptCompositionGateProvider();
    $result = new ProviderRuntime()->run_tool_loop(
        1,
        $provider,
        [['role' => 'user', 'content' => 'Create a page using images from my media library.']],
        awpt_discovery_result_for_runtime(),
        [
            'tool_registry' => awpt_register_composition_gate_tools(),
            'is_content_turn' => true,
            // Leave less than MIN_USEFUL_REQUEST_SECONDS on a 240s wall.
            'turn_started_at' => microtime(true) - 230,
            'turn_wall_seconds' => 240,
        ],
    );

    Assert::same(0, $provider->completions, 'must not schedule a follow-up with unusable remaining time');
    Assert::true(
        str_contains($result['content'], 'no change was staged'),
        'wall exhaustion should state that nothing was staged',
    );
    Assert::true(
        str_contains($result['content'], 'ran out of time'),
        'wall exhaustion should not blame a provider timeout that never ran',
    );
    Assert::false(
        str_contains($result['content'], 'Provider detail:'),
        'skipped calls should not invent provider error detail',
    );
}

test_provider_runtime_skips_doomed_follow_up_when_wall_nearly_gone();
