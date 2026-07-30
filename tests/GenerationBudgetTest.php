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

function test_generation_budget_inherits_content_path_on_retry(): void {
    $budget = new GenerationBudget();
    $retry = 'Try again, please.';
    $prior = 'Make a new page, please. Make a civicpress landing page for WMLS.';

    Assert::false(
        $budget->is_content_request($retry),
        'retry alone without session context must stay on the short path',
    );
    Assert::true($budget->is_content_request($retry, [
        'prior_user_messages' => [$prior, $retry],
    ]), 'retry after a page-create request should inherit the content budget');
    Assert::same(24_000, $budget->for_message($retry, 3, [
        'prior_user_messages' => [$prior, $retry],
    ]), 'retry composition after tools should get the large content budget');
    Assert::true($budget->is_content_request('Hey, try it again!', [
        'has_open_new_post_proposal' => true,
    ]), 'retry with an open new-post proposal should use the content path');
    Assert::false($budget->is_content_request('What plugins are active?', [
        'has_open_new_post_proposal' => true,
    ]), 'factual questions must not inherit content budget from an open proposal alone');
}

test_generation_budget_recognizes_generate_page_requests();
test_generation_budget_recognizes_revision_requests();
test_generation_budget_inherits_content_path_on_retry();

function test_generation_budget_gives_existing_page_edits_composition_headroom(): void {
    $budget = new GenerationBudget();
    $request = 'Hey, can you fix page 408? I would like it to be a documentation page.';

    Assert::true(
        $budget->is_content_edit_request($request),
        'an existing page formatting request should use the extended edit path',
    );
    Assert::false(
        $budget->is_content_request($request),
        'an existing page edit must not enter the new-post composition gate',
    );
    Assert::same(6_000, $budget->for_message($request), 'initial edit discovery should stay concise');
    Assert::same(24_000, $budget->for_message($request, 5), 'edit composition should fit a full document');
}

function test_generation_budget_inherits_existing_edit_path_for_preservation_follow_up(): void {
    $budget = new GenerationBudget();
    $follow_up = 'Is this the original text or modified? I would like to keep it as original as possible.';
    $context = [
        'prior_user_messages' => [
            'Hey, can you fix page 408? I would like it to be a documentation page.',
            $follow_up,
        ],
    ];

    Assert::true(
        $budget->is_content_edit_request($follow_up, $context),
        'a preservation follow-up should inherit the existing-content edit path',
    );
    Assert::same(
        24_000,
        $budget->for_message($follow_up, 7, $context),
        'a full-document preservation revision needs the post-tool composition budget',
    );
}

test_generation_budget_gives_existing_page_edits_composition_headroom();
test_generation_budget_inherits_existing_edit_path_for_preservation_follow_up();

function test_generation_budget_recognizes_cleanup_phrasing_as_edit(): void {
    $budget = new GenerationBudget();
    $request = 'Hey, can you clean up page 410?';

    Assert::true(
        $budget->is_content_edit_request($request),
        'cleanup phrasing on a named page should use the extended edit path',
    );
    Assert::false($budget->is_content_request($request), 'cleanup must not enter the new-post composition gate');
    Assert::same(6_000, $budget->for_message($request), 'initial cleanup discovery should stay concise');
    Assert::same(24_000, $budget->for_message($request, 5), 'cleanup composition should fit a full document');
    Assert::true(
        $budget->is_content_edit_request('Can you tidy page #12?'),
        'tidy + page should classify as content edit',
    );
    Assert::true(
        $budget->is_content_edit_request('page 410 cleanup please'),
        'page-id-first cleanup phrasing should classify as content edit',
    );
}

test_generation_budget_recognizes_cleanup_phrasing_as_edit();

function test_generation_budget_routes_targeted_visual_adjustments_as_edits(): void {
    $budget = new GenerationBudget();
    $message = "Adjust the icon on page #32 so that it's a bit bigger.";

    Assert::true($budget->is_content_edit_request($message), 'targeted icon resizing should be an edit turn');
    Assert::false($budget->is_content_request($message), 'an explicit existing page must not become a new-page turn');
}

test_generation_budget_routes_targeted_visual_adjustments_as_edits();

function test_generation_budget_recognizes_paragraph_fix_and_inherits_edit_follow_up(): void {
    $budget = new GenerationBudget();
    $paragraph_fix = 'I noticed you collapsed paragraph breaks in the answer to q3. Can you fix?';
    $prior_cleanup = 'Hey, can you clean up page 410? IT needs to look better.';

    Assert::true(
        $budget->is_content_edit_request($paragraph_fix),
        'paragraph-break fixes should classify as content edits on their own',
    );
    Assert::false(
        $budget->is_content_request($paragraph_fix),
        'paragraph fixes must not enter the new-post composition gate',
    );
    Assert::true($budget->is_content_edit_request('Can you fix?', [
        'prior_user_messages' => [$prior_cleanup, 'Can you fix?'],
    ]), 'short fix follow-ups should inherit the content-edit path after a page edit');
    Assert::false(
        $budget->is_content_edit_request('Can you fix?'),
        'short fix alone without prior edit context must stay off the edit path',
    );
    Assert::false($budget->is_content_edit_request('What plugins are active?', [
        'prior_user_messages' => [$prior_cleanup],
    ]), 'factual questions must not inherit the edit path from a prior cleanup');
    Assert::same(24_000, $budget->for_message($paragraph_fix, 3), 'paragraph fix composition needs edit headroom');
}

test_generation_budget_recognizes_paragraph_fix_and_inherits_edit_follow_up();
