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

function test_resolve_revisable_new_post_does_not_replace_single_candidate_with_different_title(): void {
    $candidates = [
        ['id' => 34, 'post_type' => 'page', 'title_key' => 'maternity-news'],
    ];

    $id = ActionRepository::pick_revisable_new_post_id($candidates, 'page', 'A different title');
    Assert::same(0, $id, 'a distinct supplied title should create a separate proposal');
}

function test_resolve_revisable_new_post_uses_single_candidate_when_title_is_omitted(): void {
    $candidates = [
        ['id' => 34, 'post_type' => 'page', 'title_key' => 'maternity-news'],
    ];

    $id = ActionRepository::pick_revisable_new_post_id($candidates, 'page');
    Assert::same(34, $id, 'a title-less correction may revise the only compatible open proposal');
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
test_resolve_revisable_new_post_does_not_replace_single_candidate_with_different_title();
test_resolve_revisable_new_post_uses_single_candidate_when_title_is_omitted();
test_resolve_revisable_new_post_does_not_guess_among_many();
