<?php

/**
 * Tests user-facing tool transcript formatting.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ToolResultContentFormatter;
use AWPT\Agent\ToolResultFormatter;

function test_tool_result_formatter_hides_recovered_same_tool_failures(): void {
    $content = new ToolResultFormatter()->format_for_transcript([
        [
            'tool' => 'awpt/propose-new-post',
            'status' => 'failed',
            'output' => ['error' => 'Attachment #77 is missing.'],
        ],
        [
            'tool' => 'awpt/propose-new-post',
            'status' => 'success',
            'output' => ['id' => 24, 'title' => 'Maternity landing page', 'status' => 'proposed'],
        ],
    ]);

    Assert::true(str_contains($content, 'staged action #24'), 'the successful proposal should remain visible');
    Assert::false(
        str_contains($content, 'Attachment #77 is missing'),
        'a recovered validation error should be omitted',
    );
}

test_tool_result_formatter_hides_recovered_same_tool_failures();

function test_tool_result_formatter_reports_proposal_markup_repairs(): void {
    $content = new ToolResultFormatter()->format_for_transcript([[
        'tool' => 'awpt/propose-new-post',
        'status' => 'success',
        'output' => [
            'id' => 25,
            'title' => 'Landing page',
            'status' => 'proposed',
            'payload' => [
                'repairs_applied' => [[
                    'kind' => 'cover_image_class',
                    'block_path' => '0',
                    'block_name' => 'core/cover',
                    'description' => 'Added the canonical wp-image-88 class to the block image.',
                ]],
            ],
        ],
    ]]);

    Assert::true(str_contains($content, 'repaired Gutenberg markup'), 'successful repair should be disclosed');
    Assert::true(str_contains($content, 'wp-image-88'), 'repair details should be shown');
}

test_tool_result_formatter_reports_proposal_markup_repairs();

function test_pattern_formatter_explains_zero_metadata_matches_and_shows_fallbacks(): void {
    $content = new ToolResultContentFormatter()->format('awpt/list-patterns', [
        'count' => 0,
        'patterns' => [],
        'search' => 'maternity',
        'search_note' => 'No pattern metadata matched this search. This does not mean compatible patterns are unavailable.',
        'available_count' => 49,
        'suggested_patterns' => [[
            'name' => 'civicpress/header-hero',
            'title' => 'Hero Header',
        ]],
    ]);

    Assert::true(
        str_contains($content, 'matching metadata search “maternity”'),
        'the transcript should distinguish metadata matches from total registered patterns',
    );
    Assert::true(
        str_contains($content, '49 total patterns available'),
        'the transcript should make the broader catalog visible',
    );
    Assert::true(
        str_contains($content, 'civicpress/header-hero'),
        'the transcript should expose an exact grounded fallback identifier',
    );
}

test_pattern_formatter_explains_zero_metadata_matches_and_shows_fallbacks();

function test_read_pattern_formatter_uses_compact_summary(): void {
    $content = new ToolResultContentFormatter()->format('awpt/read-pattern', [
        'name' => 'civicpress/header-hero',
        'title' => 'Hero Header',
        'block_count' => 1,
        'content' => str_repeat('large pattern markup ', 1000),
        'blocks' => [['name' => 'core/cover']],
    ]);

    Assert::true(str_contains($content, 'civicpress/header-hero'), 'pattern identity should remain visible');
    Assert::false(
        str_contains($content, 'large pattern markup'),
        'raw pattern payload should not flood the transcript',
    );
}

test_read_pattern_formatter_uses_compact_summary();

function test_incomplete_turn_formatter_summarizes_evidence_without_dumping_it(): void {
    $content = new ToolResultFormatter()->format_incomplete_turn([
        [
            'tool' => 'awpt/read-content',
            'status' => 'success',
            'output' => ['id' => 408, 'title' => 'Stamping Fee', 'type' => 'page'],
        ],
        [
            'tool' => 'awpt/read-block-tree',
            'status' => 'success',
            'output' => ['count' => 39, 'blocks' => []],
        ],
        [
            'tool' => 'awpt/read-pattern',
            'status' => 'success',
            'output' => ['title' => 'Documentation Page', 'content' => str_repeat('markup', 1000)],
        ],
    ], 'Operation timed out.');

    Assert::true(str_contains($content, '#408 Stamping Fee (page)'), 'content identity should be preserved');
    Assert::true(str_contains($content, '39 blocks'), 'structural evidence should be summarized');
    Assert::true(str_contains($content, 'Documentation Page'), 'comparison evidence should be summarized');
    Assert::true(str_contains($content, 'no change was staged'), 'the operational outcome should be explicit');
    Assert::false(str_contains($content, 'markupmarkup'), 'raw evidence must not leak into the fallback');
}

test_incomplete_turn_formatter_summarizes_evidence_without_dumping_it();

function test_incomplete_turn_formatter_does_not_deny_successful_staging(): void {
    $content = new ToolResultFormatter()->format_incomplete_turn([[
        'tool' => 'awpt/propose-content-update',
        'status' => 'success',
        'output' => [
            'id' => 43,
            'title' => 'Preserve Stamping Fee copy',
            'status' => 'proposed',
        ],
    ]], 'Operation timed out.');

    Assert::true(str_contains($content, 'staged proposed action #43'), 'successful staging should be reported');
    Assert::true(str_contains($content, 'original content has not been changed'), 'staging semantics should be clear');
    Assert::false(str_contains($content, 'no change was staged'), 'the fallback must not contradict tool evidence');
}

test_incomplete_turn_formatter_does_not_deny_successful_staging();
