<?php

/** Agent-loop budget contract. @package AWPT */

declare(strict_types=1);

use AWPT\Agent\ProviderInterface;
use AWPT\Agent\ProviderRuntime;
use AWPT\Agent\ToolRegistry;

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

    Assert::same(
        1,
        substr_count((string) $result['content'], 'Tool demo/read returned'),
        'timeout finalization should not duplicate completed discovery output',
    );
    Assert::true(
        str_contains((string) $result['content'], 'Request timed out'),
        'the actual provider failure should be clear',
    );
}

test_provider_runtime_formats_discovery_only_once_after_follow_up_failure();

final class AwptCompositionGateProvider implements ProviderInterface {
    public int $completions = 0;
    public bool $timeout_first = false;
    public bool $timeout_always = false;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        ++$this->completions;
        Assert::same(1, count($tools), 'composition phase should expose only the proposal tool');
        Assert::true(
            is_array($options['tool_choice'] ?? null),
            'direct providers should receive a forced proposal function choice',
        );
        Assert::same(
            16_000,
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
    Assert::true(str_contains($result['content'], 'proposal retry'), 'failure should identify the orchestration phase');
    Assert::true(
        str_contains($result['content'], '7 verified tool results'),
        'failure should report the evidence count',
    );
    Assert::true(
        str_contains($result['content'], '16000-token completion budget'),
        'failure should report the bounded budget',
    );
}

test_provider_runtime_forces_proposal_after_sufficient_discovery();
test_provider_runtime_retries_timed_out_finalization_once_with_compact_evidence();
test_provider_runtime_explains_failed_finalization_with_turn_diagnostics();
