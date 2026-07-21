<?php

/**
 * Tests for AWPT\Agent\ToolResultTruncator.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ToolResultTruncator;

function test_tool_result_truncator_clips_large_read_content_output(): void {
    $truncator = new ToolResultTruncator();
    $output = [
        'id' => 42,
        'title' => 'About',
        'content' => str_repeat('block markup ', 4000),
        'plain_text' => str_repeat('plain ', 2000),
        'meta' => [
            'hero' => str_repeat('x', 5000),
        ],
    ];

    $provider = $truncator->for_provider('awpt/read-content', $output);

    Assert::true((bool) ($provider['truncated'] ?? false), 'large read-content output should truncate for provider');
    Assert::same('About', $provider['title'] ?? null, 'truncated output should keep title');
}

function test_tool_result_truncator_keeps_proposal_outputs(): void {
    $truncator = new ToolResultTruncator();
    $output = [
        'action_id' => 9,
        'title' => str_repeat('Proposal ', 1000),
        'payload' => ['post_content' => str_repeat('content ', 1000)],
    ];

    $provider = $truncator->for_provider('awpt/propose-new-post', $output);

    Assert::false((bool) ($provider['truncated'] ?? false), 'proposal outputs should remain intact');
    Assert::same(9, $provider['action_id'] ?? null, 'proposal payload should be preserved');
}

function test_tool_result_truncator_removes_duplicate_pattern_tree_for_provider(): void {
    $provider = new ToolResultTruncator()->for_provider('awpt/read-pattern', [
        'name' => 'civicpress/header-hero',
        'title' => 'Hero Header',
        'content' => '<!-- wp:cover --><div>Hero</div><!-- /wp:cover -->',
        'blocks' => [['name' => 'core/cover', 'text_excerpt' => str_repeat('duplicate ', 500)]],
    ]);

    Assert::true(array_key_exists('content', $provider), 'adaptable raw pattern content should remain available');
    Assert::false(
        array_key_exists('blocks', $provider),
        'duplicated normalized tree should not consume provider context',
    );
}

test_tool_result_truncator_clips_large_read_content_output();
test_tool_result_truncator_keeps_proposal_outputs();
test_tool_result_truncator_removes_duplicate_pattern_tree_for_provider();
