<?php

/** Agent generation-budget tests. @package AWPT */

declare(strict_types=1);

use AWPT\Agent\GenerationBudget;

function test_generation_budget_recognizes_generate_page_requests(): void {
    $budget = new GenerationBudget();
    $message = 'Generate an original maternity clothing landing page with several images.';

    Assert::true($budget->is_content_request($message), 'generate landing page should use the content workflow');
    Assert::same(6_000, $budget->for_message($message), 'initial discovery should stay concise');
    Assert::same(24_000, $budget->for_message($message, 4), 'post-discovery composition should have a large budget');
}

test_generation_budget_recognizes_generate_page_requests();
