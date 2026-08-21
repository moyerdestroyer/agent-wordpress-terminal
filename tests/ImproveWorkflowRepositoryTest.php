<?php

/** Durable Improve workflow transition tests. */

declare(strict_types=1);

use AWPT\Database\ImproveWorkflowRepository;

function test_improve_workflow_happy_path_and_duplicate_act(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $evaluating = $repository->begin_evaluate(701, 848, 'eval-1');

    Assert::same('evaluating', $evaluating['state'] ?? null, 'evaluation starts durably');
    Assert::same(848, $evaluating['focus_post_id'] ?? null, 'workflow binds focus');

    $ready = $repository->plan_ready(
        701,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"replace","title":"Replace path 2","op":"batch","paths":["2"],"brief":"Replace path 2"}]'
        . "\n```",
        awpt_test_improve_tree_evidence(848),
    );
    Assert::same('plan_ready', $ready['state'] ?? null, 'valid plan becomes executable');

    $acting = $repository->begin_act(701, (string) $ready['id'], 848, 'act-1');
    Assert::false(is_wp_error($acting), 'matching plan can act');
    Assert::same('acting', is_array($acting) ? $acting['state'] ?? null : null, 'act transition');

    $duplicate = $repository->begin_act(701, (string) $ready['id'], 848, 'act-2');
    Assert::true(is_wp_error($duplicate), 'duplicate act fails closed');
    if (is_wp_error($duplicate)) {
        Assert::same('awpt_improve_workflow_not_executable', $duplicate->get_error_code(), 'duplicate code');
    }

    $staged = $repository->finish_act(701, [91], ['status' => 'staged']);
    Assert::same('staged', $staged['state'] ?? null, 'action produces staged state');
    Assert::same([91], $staged['action_ids'] ?? null, 'action ids bound');

    $repository->sync_action(701, 91, 'applied');
    Assert::same('applied', $repository->get(701)['state'] ?? null, 'apply synchronizes workflow');

    $repository->sync_action(701, 91, 'rolled_back');
    Assert::same('rolled_back', $repository->get(701)['state'] ?? null, 'rollback synchronizes workflow');
}

function test_improve_workflow_rejects_focus_change_and_empty_plan(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $evaluating = $repository->begin_evaluate(702, 848, 'eval-2');
    $failed = $repository->plan_ready(702, '   ');
    Assert::same('failed', $failed['state'] ?? null, 'empty plan fails instead of freehand fallback');

    $ready = $repository->begin_evaluate(703, 848, 'eval-3');
    $ready = $repository->plan_ready(
        703,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"keep","title":"Keep path 0","op":"batch","paths":["0"],"brief":"Keep path 0"}]'
        . "\n```",
        awpt_test_improve_tree_evidence(848),
    );
    $mismatch = $repository->begin_act(703, (string) $ready['id'], 853, 'act-3');
    Assert::true(is_wp_error($mismatch), 'changed focus fails closed');
    if (is_wp_error($mismatch)) {
        Assert::same('awpt_improve_focus_mismatch', $mismatch->get_error_code(), 'focus mismatch code');
    }
    Assert::true('' !== (string) ($evaluating['id'] ?? ''), 'workflow id minted');
}

function test_improve_workflow_advances_cursor_and_refuses_fallback_stub(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(704, 835, 'eval-units');
    $ready = $repository->plan_ready(
        704,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"one","title":"Merge links","op":"batch","paths":["2"],"brief":"Merge split links"},'
        . '{"id":"two","title":"Add H2","op":"batch","paths":["0"],"brief":"Insert H2 headings"}]'
        . "\n```",
        awpt_test_improve_tree_evidence(835),
    );
    Assert::same('plan_ready', $ready['state'] ?? null, 'parsed units are executable');
    Assert::same(2, count($ready['units'] ?? []), 'two units stored');
    Assert::same(0, $ready['cursor'] ?? null, 'cursor starts at first unit');

    $acting = $repository->begin_act(704, (string) $ready['id'], 835, 'act-1');
    Assert::false(is_wp_error($acting), 'first unit can act');
    $staged = $repository->finish_act(704, [201], ['status' => 'staged']);
    Assert::same('staged', $staged['state'] ?? null, 'first unit staged');
    Assert::same(1, $staged['cursor'] ?? null, 'cursor advances after a staged unit');

    $repository->sync_action(704, 201, 'applied');
    $after = $repository->get(704);
    Assert::same('plan_ready', $after['state'] ?? null, 'remaining units reopen plan_ready after apply');
    Assert::true(\AWPT\Database\ImproveWorkflowRepository::has_remaining_units($after ?? []), 'second unit remains');

    $again = $repository->begin_act(704, (string) ($after['id'] ?? ''), 835, 'act-2');
    Assert::false(is_wp_error($again), 'continue act is allowed after apply');

    $repository->begin_evaluate(705, 829, 'eval-stub');
    $stub = $repository->plan_ready(
        705,
        "## Execution plan\n\n### Recommended next ops\n- No change if evidence shows the page is already fine.\n\n_Plan finalized from verified evidence after evaluate tool budget was exhausted._",
    );
    Assert::same('failed', $stub['state'] ?? null, 'exhausted-budget stub does not become executable');
    Assert::same('awpt_improve_plan_missing', $stub['error_code'] ?? null, 'stub fails as missing plan');
}

function test_improve_workflow_persists_pattern_evidence(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(706, 848, 'eval-patterns');

    $tool_calls = [
        ['tool' => 'awpt/read-block-tree', 'status' => 'success', 'output' => ['blocks' => []]],
        [
            'tool' => 'awpt/recommend-patterns',
            'status' => 'success',
            'output' => [
                'recommendations' => [
                    [
                        'pattern' => [
                            'name' => 'civicpress/faq',
                            'title' => 'FAQ',
                            'domain' => [
                                'role' => 'content',
                                'summary' => 'A compact FAQ section.',
                                'use_when' => ['Several concise questions share one topic.'],
                                'avoid_when' => ['There is only one short answer.'],
                            ],
                        ],
                        'rationale' => 'FAQ intent',
                    ],
                    [
                        'pattern' => ['name' => 'civicpress/toc', 'title' => 'TOC', 'role' => 'navigation'],
                        'rationale' => 'Anchor nav',
                    ],
                ],
            ],
        ],
        [
            'tool' => 'awpt/recommend-patterns',
            'status' => 'failed',
            'output' => [
                'recommendations' => [
                    ['pattern' => ['name' => 'civicpress/should-not-appear'], 'rationale' => 'failed call'],
                ],
            ],
        ],
    ];

    $ready = $repository->plan_ready(
        706,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"one","title":"Add FAQ section","op":"pattern_insert","paths":["4"],'
        . '"pattern_name":"civicpress/faq","brief":"Insert FAQ section after path 4"}]'
        . "\n```",
        $tool_calls,
    );

    Assert::same('plan_ready', $ready['state'] ?? null, 'complete pattern unit is executable');
    $evidence = $ready['pattern_evidence'] ?? [];
    Assert::same(2, count($evidence), 'successful recommendations persist, failures skipped');
    Assert::same('civicpress/faq', $evidence[0]['name'] ?? '', 'top pattern name persisted');
    Assert::same('FAQ intent', $evidence[0]['rationale'] ?? '', 'ranking rationale persisted');
    Assert::same(
        ['Several concise questions share one topic.'],
        $evidence[0]['use_when'] ?? [],
        'fit guidance persisted',
    );
    Assert::same(['There is only one short answer.'], $evidence[0]['avoid_when'] ?? [], 'avoid guidance persisted');
    Assert::same(
        ['civicpress/faq', 'civicpress/toc'],
        array_column($evidence, 'name'),
        'evidence stays in ranked order',
    );

    $capped = $repository->pattern_evidence_from_tool_calls(array_map(
        static fn(int $index): array => [
            'tool' => 'awpt/recommend-patterns',
            'status' => 'success',
            'output' => [
                'recommendations' => [
                    ['pattern' => ['name' => 'civicpress/pattern-' . $index], 'rationale' => 'rank ' . $index],
                ],
            ],
        ],
        range(1, 8),
    ));
    Assert::same(5, count($capped), 'pattern evidence is capped at five entries');
}

function test_improve_workflow_rejects_unevidenced_pattern_name(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(716, 848, 'eval-unevidenced-pattern');
    $failed = $repository->plan_ready(
        716,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"one","title":"Add docs layout","op":"pattern_replace","paths":["document"],'
        . '"pattern_name":"civicpress/layout-page-documentation","brief":"Use the documentation layout"}]'
        . "\n```",
        awpt_test_improve_tree_evidence(848),
    );

    Assert::same('failed', $failed['state'] ?? null, 'pattern names require recommendation evidence');
    Assert::same(
        'awpt_improve_pattern_evidence_missing',
        $failed['error_code'] ?? null,
        'missing evidence has a specific lifecycle error',
    );
}

function test_improve_workflow_rejects_incomplete_units(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(707, 828, 'eval-sparse');
    $failed = $repository->plan_ready(
        707,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"unit","op":"pattern_replace","paths":[],"brief":"","pattern_name":""}]'
        . "\n```",
    );
    Assert::same('failed', $failed['state'] ?? null, 'sparse pattern unit fails closed');
    Assert::same('awpt_improve_units_incomplete', $failed['error_code'] ?? null, 'incomplete units code');
}

function test_improve_workflow_does_not_enqueue_none_units(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(712, 827, 'eval-mixed-none');
    $mixed = $repository->plan_ready(
        712,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"edit","op":"batch","paths":["1"],"brief":"Improve path 1"},'
        . '{"id":"advisory","op":"none","paths":["document"],"brief":"Consider contact data later"}]'
        . "\n```",
        awpt_test_improve_tree_evidence(827),
    );
    Assert::same('plan_ready', $mixed['state'] ?? null, 'mixed plan keeps its executable work');
    Assert::same(['edit'], array_column($mixed['units'] ?? [], 'id'), 'none unit is omitted from the act queue');

    $repository->begin_evaluate(713, 827, 'eval-only-none');
    $none = $repository->plan_ready(
        713,
        "## Plan\nThe page already satisfies the request.\n\n```awpt-units\n"
        . '[{"id":"done","op":"none","paths":[],"brief":"No change is warranted"}]'
        . "\n```",
        awpt_test_improve_tree_evidence(827),
    );
    Assert::same('no_change', $none['state'] ?? null, 'all-none evaluation completes without an act turn');
    Assert::same([], $none['units'] ?? null, 'no-change workflow has no executable units');

    $repository->begin_evaluate(714, 827, 'eval-none-without-read');
    $unverified = $repository->plan_ready(
        714,
        "## Plan\nNo change.\n\n```awpt-units\n"
        . '[{"id":"done","op":"none","paths":[],"brief":"No change is warranted"}]'
        . "\n```",
    );
    Assert::same('failed', $unverified['state'] ?? null, 'no-change conclusion still requires verified structure');
    Assert::same(
        'awpt_improve_structure_evidence_missing',
        $unverified['error_code'] ?? null,
        'unverified no-change uses the evidence error',
    );
}

function test_improve_workflow_rejects_empty_paths_and_accepts_explicit_full_page(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(708, 828, 'eval-fullpage');
    $failed = $repository->plan_ready(
        708,
        "## Plan\n\n```awpt-units\n"
        . '[{"op":"pattern_replace","paths":[],"brief":"Replace with documentation layout",'
        . '"pattern_name":"civicpress/layout-page-documentation","title":"Doc layout"}]'
        . "\n```",
    );
    Assert::same('failed', $failed['state'] ?? null, 'empty paths fail closed');
    Assert::same('awpt_improve_units_incomplete', $failed['error_code'] ?? null, 'incomplete for empty paths');

    $repository->begin_evaluate(709, 828, 'eval-document');
    $ready = $repository->plan_ready(
        709,
        "## Plan\n\n```awpt-units\n"
        . '[{"op":"pattern_replace","paths":["document"],"brief":"Replace with documentation layout",'
        . '"pattern_name":"civicpress/layout-page-documentation","title":"Doc layout"}]'
        . "\n```",
        [
            ...awpt_test_improve_tree_evidence(828),
            [
                'tool' => 'awpt/recommend-patterns',
                'status' => 'success',
                'output' => [
                    'recommendations' => [[
                        'pattern' => ['name' => 'civicpress/layout-page-documentation'],
                        'rationale' => 'Fits long-form documentation.',
                    ]],
                ],
            ],
        ],
    );
    Assert::same('plan_ready', $ready['state'] ?? null, 'explicit document path is executable');
    Assert::same(['document'], $ready['units'][0]['paths'] ?? null, 'document target remains explicit');
}

test_improve_workflow_happy_path_and_duplicate_act();
test_improve_workflow_rejects_focus_change_and_empty_plan();
test_improve_workflow_advances_cursor_and_refuses_fallback_stub();
test_improve_workflow_persists_pattern_evidence();
test_improve_workflow_rejects_unevidenced_pattern_name();
test_improve_workflow_rejects_incomplete_units();
test_improve_workflow_does_not_enqueue_none_units();
test_improve_workflow_rejects_empty_paths_and_accepts_explicit_full_page();
test_improve_workflow_rejects_deferred_single_pattern_unit();
test_improve_workflow_stores_tree_snapshot();

function test_improve_workflow_rejects_deferred_single_pattern_unit(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(710, 828, 'eval-defer');
    $failed = $repository->plan_ready(
        710,
        "## Plan\nAfter the pattern is placed, subsequent units will populate the FAQ.\n\n```awpt-units\n"
        . '[{"id":"u1","op":"pattern_replace","paths":["document"],'
        . '"pattern_name":"civicpress/layout-page-documentation",'
        . '"brief":"Replace layout; subsequent units will populate copy"}]'
        . "\n```",
        [
            [
                'tool' => 'awpt/read-block-tree',
                'status' => 'success',
                'output' => [
                    'top_level_sections' => array_map(
                        static fn(int $i): array => ['path' => (string) $i, 'heading' => "Q$i", 'role' => 'body'],
                        range(0, 19),
                    ),
                ],
            ],
        ],
    );
    Assert::same('failed', $failed['state'] ?? null, 'deferred single unit fails plan_ready');
    Assert::same('awpt_improve_units_incomplete', $failed['error_code'] ?? null, 'incomplete code');
}

function test_improve_workflow_stores_tree_snapshot(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(711, 828, 'eval-tree');
    $ready = $repository->plan_ready(
        711,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"u1","op":"batch","paths":["0"],"brief":"Fix heading on path 0"}]'
        . "\n```",
        [
            [
                'tool' => 'awpt/read-block-tree',
                'status' => 'success',
                'output' => [
                    'top_level_sections' => [
                        ['path' => '0', 'heading' => 'Intro', 'role' => 'header'],
                        ['path' => '1', 'heading' => 'Body', 'role' => 'body'],
                    ],
                ],
            ],
        ],
    );
    Assert::same('plan_ready', $ready['state'] ?? null, 'simple batch plan ready');
    Assert::same(2, $ready['tree_snapshot']['top_level_section_count'] ?? null, 'tree snapshot stored');
    Assert::same('0', $ready['tree_snapshot']['sections'][0]['path'] ?? null, 'snapshot path');
}
