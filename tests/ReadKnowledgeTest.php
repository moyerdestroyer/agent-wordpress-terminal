<?php

/** Read-knowledge permission and ID contracts. @package AWPT */

declare(strict_types=1);

use AWPT\Abilities\ReadKnowledge;

function test_read_knowledge_reports_invalid_id_instead_of_permission_failure(): void {
    awpt_test_reset_state();
    $ability = new ReadKnowledge();

    Assert::true($ability->can_read([
        'id' => 0,
    ]), 'admins may receive actionable invalid-ID feedback instead of a permission denial');
    Assert::true($ability->can_read(['id' => 999_999]), 'unknown post IDs must not fail the ability permission gate');

    $error = $ability->execute(['id' => 0]);
    Assert::true(is_wp_error($error), 'zero is not a knowledge target');
    Assert::same(
        'awpt_invalid_knowledge_id',
        $error->get_error_code(),
        'zero should direct the agent back to source_post_id from search',
    );
}

function test_read_knowledge_accepts_source_post_id_alias(): void {
    awpt_test_reset_state();
    $ability = new ReadKnowledge();

    // source_post_id alone is enough; missing posts become not-found, not a permission denial.
    $error = $ability->execute(['source_post_id' => 42]);
    Assert::true(is_wp_error($error), 'unknown source_post_id should error');
    Assert::same(
        'awpt_knowledge_not_found',
        $error->get_error_code(),
        'source_post_id should be used when id is omitted',
    );
}

test_read_knowledge_reports_invalid_id_instead_of_permission_failure();
test_read_knowledge_accepts_source_post_id_alias();
