<?php

/**
 * Tool batch partition and order contracts.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ToolBatchRunner;
use AWPT\Agent\ToolParallelism;

function test_tool_parallelism_classifies_proposals_as_serial(): void {
    $p = new ToolParallelism();

    Assert::true($p->is_serial_only('awpt/propose-new-post'), 'proposals must be serial');
    Assert::true($p->is_serial_only('awpt/search-knowledge'), 'knowledge searches stay serial');
    Assert::true($p->is_parallel_safe('awpt/list-patterns'), 'list-patterns is parallel-safe');
    Assert::true($p->is_parallel_safe('awpt/read-pattern'), 'read-pattern is parallel-safe');
    Assert::false($p->is_parallel_safe('awpt/propose-new-post'), 'proposals are not parallel-safe');
}

function test_tool_batch_runner_preserves_provider_order(): void {
    $runner = new ToolBatchRunner();
    $items = [
        ['index' => 0, 'tool_name' => 'awpt/list-patterns', 'raw' => ['id' => 'a']],
        ['index' => 1, 'tool_name' => 'awpt/propose-new-post', 'raw' => ['id' => 'b']],
        ['index' => 2, 'tool_name' => 'awpt/read-pattern', 'raw' => ['id' => 'c']],
    ];
    $order = [];

    $results = $runner->run(
        $items,
        static function (array $raw, int $index) use (&$order): array {
            $order[] = $index;

            return [
                'tool_call' => [
                    'tool' => (string) ($raw['id'] ?? ''),
                    'status' => 'success',
                    'input' => [],
                    'output' => [],
                ],
                'message' => ['role' => 'tool', 'tool_call_id' => (string) $index, 'content' => '{}'],
                'visual_message' => null,
            ];
        },
        static function (): void {},
    );

    Assert::same(
        ['a', 'b', 'c'],
        array_map(static fn(array $r): string => (string) $r['tool_call']['tool'], $results),
        'results must reassemble in original provider order',
    );
    // Parallel-safe (0,2) run before serial proposal (1), but output order is 0,1,2.
    Assert::same([0, 2, 1], $order, 'execution may batch parallel-safe tools before serial ones');
}

test_tool_parallelism_classifies_proposals_as_serial();
test_tool_batch_runner_preserves_provider_order();
