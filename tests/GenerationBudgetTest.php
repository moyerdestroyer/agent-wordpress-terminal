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

function test_generation_budget_recognizes_revision_requests(): void {
    $budget = new GenerationBudget();
    $message = 'Good, but I need a latest/recent posts block at the end or something. You know, the civicpress one.';

    Assert::true(
        $budget->is_content_request($message),
        'revision requests that add a section/block should use the content workflow budget',
    );
    Assert::same(6_000, $budget->for_message($message), 'revision discovery should stay concise');
    Assert::same(24_000, $budget->for_message($message, 2), 'revision composition should get the large budget');
    Assert::false(
        $budget->is_content_request('What plugins are active?'),
        'plain factual questions should stay on the short path',
    );
}

test_generation_budget_recognizes_generate_page_requests();
test_generation_budget_recognizes_revision_requests();
