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

function test_provider_executor_collapses_semantically_identical_proposals(): void {
    awpt_test_reset_state();
    $executions = 0;
    add_filter('awpt_mcp_tools', static fn(): array => [[
        'name' => 'awpt/propose-content-update',
        'description' => 'Stage complete content.',
        'input_schema' => ['type' => 'object'],
        'readonly' => false,
        'destructive' => false,
        'requires_approval' => true,
    ]]);
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name, array $input) use (&$executions): array {
            unset($result, $tool_name, $input);
            ++$executions;

            return ['id' => 77, 'status' => 'proposed'];
        },
        10,
        3,
    );
    $execution = new ProviderToolCallExecutor()->execute(
        [
            [
                'id' => 'duplicate-one',
                'function' => [
                    'name' => 'awpt__propose_content_update',
                    'arguments' => '{"post_id":580,"post_title":"Updated"}',
                ],
            ],
            [
                'id' => 'duplicate-two',
                'function' => [
                    'name' => 'awpt__propose_content_update',
                    'arguments' => '{ "post_title": "Updated", "post_id": 580 }',
                ],
            ],
        ],
        new ToolRegistry(),
        1,
    );

    Assert::same(1, $executions, 'equivalent proposal arguments execute once');
    Assert::same(1, count($execution['tool_calls']), 'only the canonical proposal is persisted');
    Assert::same(2, count($execution['messages']), 'every duplicate provider call ID receives a response');
    Assert::same('duplicate-one', $execution['messages'][0]['tool_call_id'] ?? '', 'canonical response keeps its ID');
    Assert::same('duplicate-two', $execution['messages'][1]['tool_call_id'] ?? '', 'duplicate response uses its ID');
    Assert::same(
        $execution['messages'][0]['content'] ?? '',
        $execution['messages'][1]['content'] ?? null,
        'duplicate call receives the canonical result',
    );
}

test_provider_executor_rejects_tools_not_offered_on_the_request();
test_find_abilities_returns_safe_names_for_next_round_activation();
test_provider_executor_transports_scalar_ability_input_and_output();
function awpt_test_register_proposal_tool(string $name): void {
    add_filter('awpt_mcp_tools', static function (array $tools) use ($name): array {
        $tools[] = [
            'name' => $name,
            'description' => 'Stage a proposal.',
            'input_schema' => ['type' => 'object'],
            'readonly' => false,
            'destructive' => false,
            'requires_approval' => true,
        ];

        return $tools;
    });
}

/** @return list<array<string, mixed>> */
function awpt_test_general_information_changes(): array {
    return [
        [
            'kind' => 'replace_inner_html',
            'block_path' => '2',
            'expected_fingerprint' => '37be8b9eb954523b5d15d7371b0948e1978fb61ee9d99307f9e024cb6cc69a33',
            'inner_html' => 'Information about the insurers on the LASLI can be found on the <a href=\'/resources/insurer-member-lookup\'>LASLI insurer member lookup</a>.',
        ],
        [
            'kind' => 'remove',
            'block_path' => '3',
            'expected_fingerprint' => 'dbf979e04f404b88e49b830d2cde9aa476307976a8bdbcb63d784f98d5fe2090',
        ],
        [
            'kind' => 'remove',
            'block_path' => '4',
            'expected_fingerprint' => '11df052ecf7f07887343f19e100fb942adf21961cc81fd7b9acc781a0439496e',
        ],
        [
            'kind' => 'replace_inner_html',
            'block_path' => '5',
            'expected_fingerprint' => 'c4e76ebfd4191eaf67dc2ea43950470a0f6b8d48a57c18f8ddef738bc9920e40',
            'inner_html' => 'Additional Information about the requirements to be added to the LASLI can be found in the <a href=\'http://example.test/guide.pdf\'>California LASLI Filing Requirements Guide</a>.',
        ],
        [
            'kind' => 'remove',
            'block_path' => '6',
            'expected_fingerprint' => '0284f1a433312a58bef6797c7664290abc8df8cdc2df6df67916076ea93a781f',
        ],
        [
            'kind' => 'remove',
            'block_path' => '7',
            'expected_fingerprint' => '11df052ecf7f07887343f19e100fb942adf21961cc81fd7b9acc781a0439496e',
        ],
        [
            'kind' => 'insert',
            'block_path' => '0',
            'expected_fingerprint' => '6f7830cdf1ab2f58b7b976c4e4a641b31b067d8f60b54aa06d5f4a21ae555ebd',
            'block_name' => 'core/heading',
        ],
    ];
}

function test_provider_executor_collapses_same_mutation_with_different_card_copy(): void {
    awpt_test_reset_state();
    $executions = 0;
    $executed_session = null;
    awpt_test_register_proposal_tool('awpt/propose-block-batch-update');
    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name, array $input) use (&$executions, &$executed_session): array {
            unset($result, $tool_name);
            ++$executions;
            $executed_session = (int) ($input['session_id'] ?? 0);

            return ['id' => 88, 'status' => 'proposed'];
        },
        10,
        3,
    );
    $changes = awpt_test_general_information_changes();
    $execution = new ProviderToolCallExecutor()->execute(
        [
            [
                'id' => 'copy-bogus-session',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'session_id' => -3,
                        'post_id' => 835,
                        'title' => 'Repair fragmented links and add heading structure to General Information page',
                        'description' => 'Phase 1 merge plus the first H2 insert.',
                        'pattern_unfit_code' => 'explicit_bespoke',
                        'pattern_fallback_reason' => 'Batch repair is more conservative than a full pattern replacement.',
                        'changes' => $changes,
                    ]),
                ],
            ],
            [
                'id' => 'copy-matching-session',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'session_id' => 343,
                        'post_id' => 835,
                        'title' => 'Repair fragmented links and add heading structure to General Information page',
                        'description' => 'Same mutations; follow-up H2 after compaction.',
                        'pattern_fallback_reason' => 'Preserves existing copy and list structure.',
                        'changes' => $changes,
                    ]),
                ],
            ],
            [
                'id' => 'copy-rephrased',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'session_id' => 343,
                        'post_id' => 835,
                        'title' => 'Repair fragmented links and add heading structure (Phase 1 + partial Phase 2)',
                        'description' => 'Replace inner HTML at paths 2 and 5; remove orphans.',
                        'pattern_fallback_reason' => 'Targeted batch repair is more conservative than full replacement.',
                        'changes' => $changes,
                    ]),
                ],
            ],
            [
                'id' => 'copy-shorter-title',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'session_id' => 343,
                        'post_id' => 835,
                        'title' => 'Repair fragmented links and add heading structure (Phase 1 + H2 insert)',
                        'description' => 'Insert H2 before path 0 after merging the split links.',
                        'changes' => $changes,
                    ]),
                ],
            ],
        ],
        new ToolRegistry(),
        343,
    );

    Assert::same(1, $executions, 'identical mutations with different card copy execute once');
    Assert::same(343, $executed_session, 'the copy with the live session id should be the one staged');
    Assert::same(1, count($execution['tool_calls']), 'only the canonical proposal is persisted');
    Assert::same(4, count($execution['messages']), 'every duplicate provider call ID receives a response');
    Assert::false(
        array_reduce(
            $execution['tool_calls'],
            static fn(bool $failed, array $call): bool => (
                $failed
                || 'awpt_multiple_proposals' === ($call['output']['error_code'] ?? '')
            ),
            false,
        ),
        'equivalent DeepSeek copies must not trip the competing-proposal gate',
    );
}

function test_provider_executor_rejects_same_tool_with_different_change_paths(): void {
    awpt_test_reset_state();
    $executions = 0;
    awpt_test_register_proposal_tool('awpt/propose-block-batch-update');
    add_filter('awpt_mcp_execute_tool', static function (mixed $result) use (&$executions): mixed {
        ++$executions;

        return $result;
    });
    $execution = new ProviderToolCallExecutor()->execute(
        [
            [
                'id' => 'remove-seventeen',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'session_id' => 340,
                        'post_id' => 838,
                        'title' => 'Remove 2016 downloads',
                        'description' => 'Wrong year.',
                        'changes' => [[
                            'kind' => 'remove',
                            'block_path' => '17',
                            'expected_fingerprint' => '45a867cbe70c5747bc36dfe3708b0f838496aa8a171c955b3726df204b6c3b63',
                        ]],
                    ]),
                ],
            ],
            [
                'id' => 'remove-eight',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'session_id' => 340,
                        'post_id' => 838,
                        'title' => 'Remove 2018 duplicate downloads',
                        'description' => 'Correct year.',
                        'changes' => [[
                            'kind' => 'remove',
                            'block_path' => '8',
                            'expected_fingerprint' => '45a867cbe70c5747bc36dfe3708b0f838496aa8a171c955b3726df204b6c3b63',
                        ]],
                    ]),
                ],
            ],
        ],
        new ToolRegistry(),
        340,
    );

    Assert::same(0, $executions, 'different path sets are competing mutations');
    Assert::same(2, count($execution['messages']), 'both competing calls still receive a tool response');
    Assert::true(
        array_reduce(
            $execution['tool_calls'],
            static fn(bool $valid, array $call): bool => (
                $valid
                && 'awpt_multiple_proposals' === ($call['output']['error_code'] ?? '')
            ),
            true,
        ),
        'different change paths must still fail closed',
    );
}

function test_provider_executor_rejects_same_paths_with_different_inner_html(): void {
    awpt_test_reset_state();
    $executions = 0;
    awpt_test_register_proposal_tool('awpt/propose-block-batch-update');
    add_filter('awpt_mcp_execute_tool', static function (mixed $result) use (&$executions): mixed {
        ++$executions;

        return $result;
    });
    $base = [
        'kind' => 'replace_inner_html',
        'block_path' => '2',
        'expected_fingerprint' => '37be8b9eb954523b5d15d7371b0948e1978fb61ee9d99307f9e024cb6cc69a33',
    ];
    $execution = new ProviderToolCallExecutor()->execute(
        [
            [
                'id' => 'html-a',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'post_id' => 835,
                        'title' => 'Lookup link',
                        'description' => 'Descriptive link text.',
                        'changes' => [[
                            ...$base,
                            'inner_html' => 'See the <a href="/lookup">LASLI insurer member lookup</a>.',
                        ]],
                    ]),
                ],
            ],
            [
                'id' => 'html-b',
                'function' => [
                    'name' => 'awpt__propose_block_batch_update',
                    'arguments' => wp_json_encode([
                        'post_id' => 835,
                        'title' => 'Lookup link',
                        'description' => 'Also descriptive, different href text.',
                        'changes' => [[...$base, 'inner_html' => 'See the <a href="/lookup">member lookup</a>.']],
                    ]),
                ],
            ],
        ],
        new ToolRegistry(),
        343,
    );

    Assert::same(0, $executions, 'different replacement HTML is a competing edit');
    Assert::true(
        array_reduce(
            $execution['tool_calls'],
            static fn(bool $valid, array $call): bool => (
                $valid
                && 'awpt_multiple_proposals' === ($call['output']['error_code'] ?? '')
            ),
            true,
        ),
        'inner_html is part of mutation identity',
    );
}

test_provider_executor_collapses_semantically_identical_proposals();
test_provider_executor_rejects_multiple_proposals_before_staging_anything();
test_provider_executor_collapses_same_mutation_with_different_card_copy();
test_provider_executor_rejects_same_tool_with_different_change_paths();
test_provider_executor_rejects_same_paths_with_different_inner_html();
