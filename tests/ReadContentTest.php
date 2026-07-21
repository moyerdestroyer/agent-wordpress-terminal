<?php

/** Read-content input and permission contracts. @package AWPT */

declare(strict_types=1);

use AWPT\Abilities\ReadContent;

function test_read_content_reports_invalid_zero_id_instead_of_permission_failure(): void {
    awpt_test_reset_state();
    $ability = new ReadContent();

    Assert::true($ability->can_read(['id' => 0]), 'an editor may receive actionable invalid-ID feedback');
    Assert::same(
        'awpt_invalid_post_id',
        $ability->execute(['id' => 0])->get_error_code(),
        'zero is not a content target and should direct the agent back to discovery',
    );
}

test_read_content_reports_invalid_zero_id_instead_of_permission_failure();
