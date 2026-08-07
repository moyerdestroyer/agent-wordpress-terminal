<?php

declare(strict_types=1);

use AWPT\Knowledge\DufresneKnowledgeRefresh;

function test_dufresne_knowledge_refresh_only_schedules_successful_imports(): void {
    Assert::true(DufresneKnowledgeRefresh::should_schedule([
        'status' => 'completed',
    ]), 'a completed import should refresh knowledge');
    Assert::true(DufresneKnowledgeRefresh::should_schedule([
        'status' => 'completed_with_errors',
    ]), 'a completed import with non-fatal errors should refresh knowledge');
    Assert::false(DufresneKnowledgeRefresh::should_schedule([
        'status' => 'cancelled',
    ]), 'a cancelled import should not refresh knowledge');
    Assert::false(DufresneKnowledgeRefresh::should_schedule([
        'status' => 'incomplete',
    ]), 'an incomplete import should not refresh knowledge');
}

function test_dufresne_knowledge_refresh_rollback_marks_stale_and_schedules(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_options']['awpt_knowledge_stale'] = '0';

    DufresneKnowledgeRefresh::on_rollback(42, ['deleted_posts' => 1]);

    Assert::same(
        '1',
        (string) ($GLOBALS['awpt_test_options']['awpt_knowledge_stale'] ?? '0'),
        'rollback should mark knowledge stale',
    );
    $hooks = array_map(
        static fn(array $event): string => (string) ($event['hook'] ?? ''),
        $GLOBALS['awpt_test_scheduled'] ?? [],
    );
    Assert::true(
        in_array(DufresneKnowledgeRefresh::CRON_HOOK, $hooks, true),
        'rollback should schedule a deferred knowledge rebuild',
    );
}

test_dufresne_knowledge_refresh_only_schedules_successful_imports();
test_dufresne_knowledge_refresh_rollback_marks_stale_and_schedules();
