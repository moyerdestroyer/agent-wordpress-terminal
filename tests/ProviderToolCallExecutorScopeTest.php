<?php

/**
 * Tests provider request-scoped tool authorization.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\FindAbilities;
use AWPT\Agent\ProviderToolCallExecutor;
use AWPT\Agent\ToolRegistry;

function register_scoped_read_ability(): void {
    wp_register_ability('demo/scoped-read', [
        'description' => 'Reads a scoped demo value.',
        'input_schema' => ['type' => 'object'],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static fn(array $input): array => ['ok' => true],
        'meta' => ['annotations' => ['readonly' => true, 'destructive' => false]],
    ]);
}

function scoped_provider_call(): array {
    return [[
        'id' => 'call_scope',
        'function' => [
            'name' => 'demo__scoped_read',
            'arguments' => '{}',
        ],
    ]];
}

function test_provider_executor_rejects_tools_not_offered_on_the_request(): void {
    awpt_test_reset_state();
    register_scoped_read_ability();
    $execution = new ProviderToolCallExecutor()->execute(
        scoped_provider_call(),
        new ToolRegistry(),
        1,
        ['offered_tool_names' => ['awpt/find-abilities']],
    );

    Assert::same(
        'rejected',
        $execution['tool_calls'][0]['status'] ?? null,
        'a discovered but undeclared tool must not execute',
    );
}

function test_find_abilities_returns_safe_names_for_next_round_activation(): void {
    awpt_test_reset_state();
    register_scoped_read_ability();
    new FindAbilities()->register();
    $result = new FindAbilities()->execute(['query' => 'scoped']);

    Assert::true(
        in_array('demo/scoped-read', $result['activated'] ?? [], true),
        'ability search should return safe matching names for next-round activation',
    );
}

function test_provider_executor_transports_scalar_ability_input_and_output(): void {
    awpt_test_reset_state();
    wp_register_ability('demo/scalar-length', [
        'description' => 'Returns a string length.',
        'input_schema' => ['type' => 'string'],
        'output_schema' => ['type' => 'integer'],
        'execute_callback' => static fn(string $input): int => strlen($input),
        'meta' => ['annotations' => ['readonly' => true, 'destructive' => false]],
    ]);
    $execution = new ProviderToolCallExecutor()->execute(
        [[
            'id' => 'call_scalar',
            'function' => [
                'name' => 'demo__scalar_length',
                'arguments' => '{"value":"hello"}',
            ],
        ]],
        new ToolRegistry(),
        1,
        ['offered_tool_names' => ['demo/scalar-length']],
    );

    Assert::same('hello', $execution['tool_calls'][0]['input'] ?? null, 'provider envelope should unwrap');
    Assert::same(5, $execution['tool_calls'][0]['output'] ?? null, 'scalar output should remain scalar');
    Assert::same(
        '5',
        $execution['messages'][0]['content'] ?? null,
        'scalar output should be valid JSON in the provider tool response',
    );
}

function test_provider_executor_rejects_multiple_proposals_before_staging_anything(): void {
    awpt_test_reset_state();
    $executions = 0;
    add_filter('awpt_mcp_tools', static fn(): array => [
        [
            'name' => 'awpt/propose-content-update',
            'description' => 'Stage complete content.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
        [
            'name' => 'awpt/propose-block-insert',
            'description' => 'Stage a block insertion.',
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ],
    ]);
    add_filter('awpt_mcp_execute_tool', static function (mixed $result) use (&$executions): mixed {
        ++$executions;

        return $result;
    });
    $execution = new ProviderToolCallExecutor()->execute(
        [
            [
                'id' => 'proposal-one',
                'function' => [
                    'name' => 'awpt__propose_content_update',
                    'arguments' => '{"post_id":580}',
                ],
            ],
            [
                'id' => 'proposal-two',
                'function' => [
                    'name' => 'awpt__propose_block_insert',
                    'arguments' => '{"post_id":580}',
                ],
            ],
        ],
        new ToolRegistry(),
        1,
    );

    Assert::same(0, $executions, 'a competing proposal batch must not stage its first mutation');
    Assert::same(2, count($execution['messages']), 'every provider call still needs a matching tool response');
    Assert::true(
        array_reduce(
            $execution['tool_calls'],
            static fn(bool $valid, array $call): bool => (
                $valid
                && 'failed' === ($call['status'] ?? '')
                && 'awpt_multiple_proposals' === ($call['output']['error_code'] ?? '')
            ),
            true,
        ),
        'every competing proposal should receive the same atomicity correction',
    );
}

test_provider_executor_rejects_tools_not_offered_on_the_request();
test_find_abilities_returns_safe_names_for_next_round_activation();
test_provider_executor_transports_scalar_ability_input_and_output();
test_provider_executor_rejects_multiple_proposals_before_staging_anything();
