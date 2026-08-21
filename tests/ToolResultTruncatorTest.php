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

function test_tool_result_truncator_projects_proposal_output_for_provider_but_keeps_storage(): void {
    $truncator = new ToolResultTruncator();
    $output = [
        'action_id' => 9,
        'title' => str_repeat('Proposal ', 1000),
        'payload' => [
            'operation' => 'content_update',
            'post_id' => 42,
            'original_post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
            'post_content' => str_repeat('<!-- wp:paragraph --><p>content</p><!-- /wp:paragraph -->', 1000),
        ],
    ];

    $provider = $truncator->for_provider('awpt/propose-new-post', $output);
    $storage = $truncator->for_storage('awpt/propose-new-post', $output);

    Assert::same('proposal_checkpoint_v1', $provider['provider_projection'] ?? '', 'provider gets a checkpoint');
    Assert::same(9, $provider['id'] ?? null, 'action handle should be preserved');
    Assert::same(42, $provider['payload']['post_id'] ?? null, 'proposal identity should be preserved');
    Assert::false(
        array_key_exists('post_content', is_array($provider['payload'] ?? null) ? $provider['payload'] : []),
        'raw candidate markup must not be repeated in provider context',
    );
    Assert::same(
        hash('sha256', (string) $output['payload']['post_content']),
        $provider['candidate']['sha256'] ?? '',
        'candidate checkpoint should identify the exact staged document',
    );
    Assert::same($output, $storage, 'full proposal must remain available to storage and the UI');
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

function test_tool_result_truncator_preserves_compact_pattern_fit_guidance(): void {
    $recommendations = [];

    for ($index = 0; $index < 10; ++$index) {
        $recommendations[] = [
            'score' => 100 - $index,
            'matched_terms' => ['service'],
            'rationale' => 'Matches the requested service journey.',
            'pattern' => [
                'name' => 'demo/pattern-' . $index,
                'title' => 'Pattern ' . $index,
                'composition_scope' => 'section',
                'domain' => [
                    'role' => 'page-section',
                    'summary' => str_repeat('Useful summary. ', 20),
                    'use_when' => ['The section has a concrete job.'],
                    'avoid_when' => ['The same job is already represented.'],
                    'post_types' => ['page'],
                ],
                'content' => str_repeat('pattern markup ', 10),
            ],
        ];
    }

    $truncator = new ToolResultTruncator();
    $output = ['recommendations' => $recommendations, 'ranking_mode' => 'deterministic'];
    $provider = $truncator->for_provider('awpt/recommend-patterns', $output);
    $storage = $truncator->for_storage('awpt/recommend-patterns', $output);

    Assert::same('pattern_candidates_v1', $provider['provider_projection'] ?? '', 'provider projection is explicit');
    Assert::same(6, count($provider['recommendations'] ?? []), 'provider receives at most six candidates');
    Assert::same(
        ['The section has a concrete job.'],
        $provider['recommendations'][0]['use_when'] ?? [],
        'use_when survives projection',
    );
    Assert::same(
        ['The same job is already represented.'],
        $provider['recommendations'][0]['avoid_when'] ?? [],
        'avoid_when survives projection',
    );
    Assert::false(isset($provider['recommendations'][0]['pattern']), 'full nested pattern record is removed');
    Assert::same(10, count($storage['recommendations'] ?? []), 'storage retains every raw recommendation');
}

function test_tool_result_truncator_keeps_editable_get_block_markup_intact(): void {
    $inner_html = '<ul>' . str_repeat('<li>Detailed list item</li>', 500) . '</ul>';
    $provider = new ToolResultTruncator()->for_provider('awpt/get-block', [
        'id' => 42,
        'path' => '3',
        'name' => 'core/list',
        'inner_html' => $inner_html,
        'inner_html_editable' => true,
        'inner_html_truncated' => false,
    ]);

    Assert::false((bool) ($provider['truncated'] ?? false), 'editable block markup should reach the provider intact');
    Assert::same($inner_html, $provider['inner_html'] ?? null, 'get-block must preserve exact saved HTML');
}

function test_tool_result_truncator_clips_theme_file_content(): void {
    $provider = new ToolResultTruncator()->for_provider('awpt/read-theme-file', [
        'path' => 'assets/css/styles.css',
        'bytes' => 200_000,
        'content' => str_repeat('body{color:red}', 5_000),
        'matches' => array_fill(0, 20, ['term' => 'layout', 'excerpt' => str_repeat('x', 500)]),
        'absolute_path' => '/var/www/html/wp-content/themes/x/style.css',
    ]);

    Assert::true(
        mb_strlen((string) ($provider['content'] ?? ''), 'UTF-8') <= 4_100,
        'theme file content must be clipped for the provider',
    );
    Assert::false(array_key_exists('absolute_path', $provider), 'absolute paths should not be sent to the model');
    Assert::true(
        is_array($provider['matches'] ?? null) && count($provider['matches']) <= 6,
        'match list should be capped',
    );
}

function test_tool_result_truncator_removes_rendered_screenshot_bytes_but_keeps_geometry(): void {
    $provider = new ToolResultTruncator()->for_provider('awpt/inspect-rendered-element', [
        'rendered' => true,
        'screenshot_data' => 'data:image/png;base64,' . str_repeat('a', 20_000),
        'elements' => [[
            'tag' => 'svg',
            'rect' => ['width' => 32, 'height' => 32],
            'computed' => ['width' => '32px', 'height' => '32px'],
        ]],
    ]);

    Assert::true(
        !array_key_exists('screenshot_data', $provider),
        'screenshot bytes should travel as vision evidence only',
    );
    Assert::same(
        32,
        $provider['elements'][0]['rect']['width'] ?? null,
        'computed rendered geometry should remain in provider evidence',
    );
}

function test_tool_result_truncator_uses_compact_revision_context_instead_of_raw_proposal_markup(): void {
    $provider = new ToolResultTruncator()->for_provider('awpt/read-proposal', [
        'id' => 9,
        'payload' => [
            'operation' => 'new_post',
            'post_title' => 'Commander News',
            'post_type' => 'page',
            'post_content' => str_repeat('<!-- wp:group -->large<!-- /wp:group -->', 600),
            'composition_manifest' => ['patterns' => array_fill(0, 20, ['name' => 'theme/section'])],
        ],
        'revision_context' => [
            'mode' => 'path_updates',
            'editable_slots' => [['block_path' => '0.1', 'current_text' => 'Commander News']],
        ],
    ]);

    Assert::false(
        array_key_exists('post_content', is_array($provider['payload'] ?? null) ? $provider['payload'] : []),
        'raw staged markup should not bloat the revision planning round',
    );
    Assert::same(
        '0.1',
        $provider['revision_context']['editable_slots'][0]['block_path'] ?? '',
        'compact editable paths should remain available to the model',
    );
}

function test_tool_result_truncator_preserves_proposal_review_receipt_fields(): void {
    $truncator = new ToolResultTruncator();
    $output = [
        'accepted' => true,
        'decision' => 'accept',
        'action_id' => 271,
        'summary' => 'The candidate satisfies the plan.',
        'action' => ['payload' => ['post_content' => str_repeat('large candidate ', 20_000)]],
    ];

    foreach ([
        $truncator->for_provider('awpt/finalize-proposal-review', $output),
        $truncator->for_storage('awpt/finalize-proposal-review', $output),
    ] as $receipt) {
        Assert::same(true, $receipt['accepted'] ?? null, 'acceptance survives result projection');
        Assert::same('accept', $receipt['decision'] ?? null, 'decision survives result projection');
        Assert::same(271, $receipt['action_id'] ?? null, 'action handle survives result projection');
        Assert::false(array_key_exists('action', $receipt), 'large action is omitted from the finalization receipt');
        Assert::true(strlen((string) wp_json_encode($receipt)) < 2_000, 'projected receipt remains compact');
    }
}

test_tool_result_truncator_clips_large_read_content_output();
test_tool_result_truncator_projects_proposal_output_for_provider_but_keeps_storage();
test_tool_result_truncator_removes_duplicate_pattern_tree_for_provider();
test_tool_result_truncator_preserves_compact_pattern_fit_guidance();
test_tool_result_truncator_keeps_editable_get_block_markup_intact();
test_tool_result_truncator_clips_theme_file_content();
test_tool_result_truncator_removes_rendered_screenshot_bytes_but_keeps_geometry();
test_tool_result_truncator_uses_compact_revision_context_instead_of_raw_proposal_markup();
test_tool_result_truncator_preserves_proposal_review_receipt_fields();

function test_tool_result_truncator_keeps_analyze_page_brief_under_budget(): void {
    $blocks = [];

    for ($i = 0; $i < 60; ++$i) {
        $blocks[] = [
            'path' => (string) $i,
            'name' => 'core/group',
            'attributes' => [],
            'attributes_summary' => [],
            'text_excerpt' => str_repeat('FAQ answer body ', 30),
            'fingerprint' => hash('sha256', 'a' . $i),
            'inner' => [],
        ];
    }

    $provider = new ToolResultTruncator()->for_provider('awpt/analyze-page', [
        'title' => 'SLIP',
        'status' => 'publish',
        'url' => 'http://example.test/slip/',
        'headings' => ['One', 'Two', 'Three'],
        'risk_level' => 'low',
        'recommended_next_actions' => ['Review block structure'],
        'plain_text' => str_repeat('plain ', 5_000),
        'block_tree' => $blocks,
    ]);

    Assert::false((bool) ($provider['truncated'] ?? false), 'analyze-page should shrink before stubbing');
    Assert::same(['One', 'Two', 'Three'], $provider['headings'] ?? null, 'headings must survive');
    Assert::same('low', $provider['risk_level'] ?? null, 'risk must survive');
    Assert::true(
        mb_strlen((string) ($provider['plain_text'] ?? ''), 'UTF-8') <= 8_000,
        'plain_text may clip after the tree is kept',
    );
    Assert::true(
        is_array($provider['block_tree'] ?? null) && count($provider['block_tree']) > 0,
        'provider channel must keep analyze-page block_tree children',
    );
}

function test_tool_result_truncator_keeps_compact_analyze_tree_for_storage(): void {
    $blocks = [];

    for ($i = 0; $i < 20; ++$i) {
        $blocks[] = [
            'path' => (string) $i,
            'name' => 'core/group',
            'attributes' => ['layout' => ['type' => 'constrained']],
            'attributes_summary' => ['layout' => '[1 items]'],
            'text_excerpt' => str_repeat('FAQ answer body ', 30),
            'fingerprint' => hash('sha256', 'a' . $i),
            'inner' => [],
        ];
    }

    $storage = new ToolResultTruncator()->for_storage('awpt/analyze-page', [
        'title' => 'SLIP',
        'headings' => ['One'],
        'plain_text' => str_repeat('plain ', 5_000),
        'block_tree' => $blocks,
    ]);

    Assert::true(is_array($storage['block_tree'] ?? null), 'storage must retain a compact tree');
    Assert::same(
        64,
        strlen((string) ($storage['block_tree'][0]['fingerprint'] ?? '')),
        'storage tree fingerprints must survive compaction',
    );
}

function test_tool_result_truncator_keeps_complete_faq_tree(): void {
    $blocks = [];

    for ($i = 0; $i < 15; ++$i) {
        $blocks[] = [
            'path' => (string) $i,
            'name' => 'core/group',
            'fingerprint' => hash('sha256', 'tree' . $i),
            'attributes_summary' => [],
            'inner' => [
                [
                    'path' => $i . '.0',
                    'name' => 'core/heading',
                    'fingerprint' => hash('sha256', 'h' . $i),
                    'attributes_summary' => ['level' => 4],
                    'text_excerpt' => 'Where Can I view LASLI Portal Tutorial videos?',
                ],
                [
                    'path' => $i . '.1',
                    'name' => 'core/paragraph',
                    'fingerprint' => hash('sha256', 'a' . $i),
                    'text_excerpt' => 'A:',
                ],
                [
                    'path' => $i . '.2',
                    'name' => 'core/paragraph',
                    'fingerprint' => hash('sha256', 'p' . $i),
                    'text_excerpt' => 'You can view the LASLI Portal Tutorial videos on the Insurer Filing Requirements page.',
                ],
            ],
        ];
    }

    $provider = new ToolResultTruncator()->for_provider('awpt/read-block-tree', [
        'blocks' => $blocks,
        'count' => 15,
        'path_format' => 'dotted',
    ]);

    Assert::false((bool) ($provider['truncated'] ?? false), 'a 15-section FAQ tree must not be stubbed');
    Assert::same(15, count($provider['blocks'] ?? []), 'all top-level sections reach the model');
    Assert::same(
        4,
        $provider['blocks'][0]['inner'][0]['attributes_summary']['level'] ?? null,
        'heading levels survive',
    );
    Assert::same('A:', $provider['blocks'][0]['inner'][1]['text_excerpt'] ?? null, 'child paragraphs survive');
    Assert::true(ToolResultTruncator::provider_tree_is_complete($provider), 'complete-tree helper matches');
}

function test_tool_result_truncator_slices_complete_sections_when_size_capped(): void {
    $blocks = [];

    for ($i = 0; $i < 80; ++$i) {
        $inner = [];

        for ($j = 0; $j < 12; ++$j) {
            $inner[] = [
                'path' => $i . '.' . $j,
                'name' => 'core/paragraph',
                'fingerprint' => hash('sha256', 'tree' . $i . '.' . $j),
                'attributes_summary' => ['align' => 'left', 'note' => str_repeat('x', 80)],
                'text_excerpt' => str_repeat('Filing procedure paragraph with links and numbers 95814 ', 8),
            ];
        }

        $blocks[] = [
            'path' => (string) $i,
            'name' => 'core/group',
            'fingerprint' => hash('sha256', 'tree' . $i),
            'attributes_summary' => ['layout' => str_repeat('constrained ', 30)],
            'inner' => $inner,
        ];
    }

    $provider = new ToolResultTruncator()->for_provider('awpt/read-block-tree', [
        'blocks' => $blocks,
        'count' => 80,
        'path_format' => 'dotted',
        'top_level_sections' => array_map(static fn(array $block): array => [
            'path' => $block['path'],
            'heading' => 'Section ' . $block['path'],
        ], $blocks),
    ]);

    Assert::true((bool) ($provider['truncated'] ?? false), 'an oversized page still slices');
    Assert::true(
        is_array($provider['blocks'] ?? null) && count($provider['blocks']) > 0,
        'included sections are complete trees',
    );
    Assert::true(
        is_array($provider['blocks'][0]['inner'] ?? null) && count($provider['blocks'][0]['inner']) > 0,
        'included sections keep children',
    );
    Assert::true(
        is_array($provider['remaining_paths'] ?? null) && count($provider['remaining_paths']) > 0,
        'omitted sections are named as remaining paths',
    );
    Assert::true(
        is_string($provider['next'] ?? null) && str_contains((string) $provider['next'], 'remaining path'),
        'next tells the model to request remaining complete sections',
    );
    Assert::false(
        ToolResultTruncator::provider_tree_is_complete($provider),
        'a sliced tree is not treated as complete',
    );
}

test_tool_result_truncator_keeps_analyze_page_brief_under_budget();
test_tool_result_truncator_keeps_compact_analyze_tree_for_storage();
test_tool_result_truncator_keeps_complete_faq_tree();
test_tool_result_truncator_slices_complete_sections_when_size_capped();
