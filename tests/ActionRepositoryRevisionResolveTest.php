<?php

/**
 * Tests open new-post revision targeting.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Database\ActionRepository;

function test_resolve_revisable_new_post_prefers_title_match(): void {
    $candidates = [
        ['id' => 40, 'post_type' => 'page', 'title_key' => 'events'],
        ['id' => 34, 'post_type' => 'page', 'title_key' => 'maternity-news'],
    ];

    $id = ActionRepository::pick_revisable_new_post_id($candidates, 'page', 'Maternity News');
    Assert::same(34, $id, 'title match should win over the newest open proposal');
}

function test_resolve_revisable_new_post_uses_single_open_candidate(): void {
    $candidates = [
        ['id' => 34, 'post_type' => 'page', 'title_key' => 'maternity-news'],
    ];

    $id = ActionRepository::pick_revisable_new_post_id($candidates, 'page', 'A different title');
    Assert::same(34, $id, 'the only open new-post should be revised when no title match exists');
}

function test_resolve_revisable_new_post_does_not_guess_among_many(): void {
    $candidates = [
        ['id' => 40, 'post_type' => 'page', 'title_key' => 'events'],
        ['id' => 34, 'post_type' => 'page', 'title_key' => 'maternity-news'],
    ];

    $id = ActionRepository::pick_revisable_new_post_id($candidates, 'page', 'Something Else');
    Assert::same(0, $id, 'ambiguous open proposals without a title match should create a new action');
}

test_resolve_revisable_new_post_prefers_title_match();
test_resolve_revisable_new_post_uses_single_open_candidate();
test_resolve_revisable_new_post_does_not_guess_among_many();
