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

    $ready = $repository->plan_ready(701, "## Plan\n\n- Keep path 0\n- Replace path 2");
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
    $ready = $repository->plan_ready(703, "## Plan\n\n- Keep path 0");
    $mismatch = $repository->begin_act(703, (string) $ready['id'], 853, 'act-3');
    Assert::true(is_wp_error($mismatch), 'changed focus fails closed');
    if (is_wp_error($mismatch)) {
        Assert::same('awpt_improve_focus_mismatch', $mismatch->get_error_code(), 'focus mismatch code');
    }
    Assert::true('' !== (string) ($evaluating['id'] ?? ''), 'workflow id minted');
}

test_improve_workflow_happy_path_and_duplicate_act();
test_improve_workflow_rejects_focus_change_and_empty_plan();
