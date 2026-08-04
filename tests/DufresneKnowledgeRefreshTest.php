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

test_dufresne_knowledge_refresh_only_schedules_successful_imports();
