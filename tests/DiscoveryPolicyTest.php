<?php

/**
 * Adaptive discovery policy contracts.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\DiscoveryPolicy;

/** @return array<string, mixed> */
function awpt_discovery_call(string $tool, array $input = [], array $output = []): array {
    return [
        'tool' => $tool,
        'input' => $input,
        'output' => $output,
        'status' => 'success',
    ];
}

function test_discovery_policy_composes_after_required_page_evidence(): void {
    $calls = [
        awpt_discovery_call('awpt/list-patterns'),
        awpt_discovery_call('awpt/list-content', ['post_type' => 'attachment']),
        awpt_discovery_call('awpt/read-pattern', [], ['composition_scope' => 'layout']),
        awpt_discovery_call('awpt/read-pattern', [], ['composition_scope' => 'hero']),
    ];
    $decision = new DiscoveryPolicy()->decide(
        'Create a page using images from the media library.',
        $calls,
        array_slice($calls, -2),
        20,
        ['content_turn' => true],
    );

    Assert::true($decision['compose'], 'covered page request should move to composition');
    Assert::true(in_array('pattern_role:layout', $decision['coverage'], true), 'layout role should be tracked');
}

function test_discovery_policy_allows_purposeful_theme_research_that_adds_evidence(): void {
    $calls = [
        awpt_discovery_call('awpt/list-patterns'),
        awpt_discovery_call(
            'awpt/read-pattern',
            ['purpose' => 'Resolve custom card classes'],
            [
                'composition_scope' => 'layout',
                'design_dependencies' => ['requires_theme_research' => true],
            ],
        ),
        awpt_discovery_call(
            'awpt/search-knowledge',
            [
                'purpose' => 'Find the custom card responsive contract',
            ],
            [
                'novel_count' => 3,
                'exhausted' => false,
            ],
        ),
    ];
    $decision = new DiscoveryPolicy()->decide('Create a landing page.', $calls, [end($calls)], 25, [
        'content_turn' => true,
    ]);

    Assert::false($decision['compose'], 'novel purpose-driven research should remain open');
}

function test_discovery_policy_immediately_composes_when_requested_media_is_verified(): void {
    $calls = [
        awpt_discovery_call('awpt/list-patterns'),
        awpt_discovery_call('awpt/list-content', ['post_type' => 'attachment']),
        awpt_discovery_call(
            'awpt/read-pattern',
            ['purpose' => 'Establish the page layout'],
            [
                'composition_scope' => 'layout',
                'design_dependencies' => ['requires_theme_research' => true],
            ],
        ),
        awpt_discovery_call(
            'awpt/search-knowledge',
            ['purpose' => 'Find custom responsive card behavior'],
            ['novel_count' => 3, 'exhausted' => false],
        ),
    ];

    $decision = new DiscoveryPolicy()->decide(
        'Create a Magic news page and use the Teferi image from the media library.',
        $calls,
        [end($calls)],
        20,
        ['content_turn' => true],
    );

    Assert::true($decision['compose'], 'verified Media Library evidence should not trigger another research hop');
}

function test_discovery_policy_stops_exhausted_refinement(): void {
    $calls = [
        awpt_discovery_call('awpt/list-patterns'),
        awpt_discovery_call('awpt/read-pattern', [], ['composition_scope' => 'layout']),
        awpt_discovery_call(
            'awpt/search-knowledge',
            ['purpose' => 'Find another layout rule'],
            [
                'novel_count' => 0,
                'exhausted' => true,
            ],
        ),
    ];
    $decision = new DiscoveryPolicy()->decide('Create a landing page.', $calls, [end($calls)], 25, [
        'content_turn' => true,
    ]);

    Assert::true($decision['compose'], 'an exhausted query should transition to action');
}

function test_discovery_policy_does_not_treat_many_purpose_labels_as_deeper_research(): void {
    $calls = [
        awpt_discovery_call('awpt/list-patterns'),
        awpt_discovery_call('awpt/list-content', ['post_type' => 'attachment']),
        awpt_discovery_call(
            'awpt/read-pattern',
            ['purpose' => 'Primary layout'],
            [
                'composition_scope' => 'layout',
                'design_dependencies' => ['requires_theme_research' => true],
            ],
        ),
        awpt_discovery_call(
            'awpt/read-pattern',
            ['purpose' => 'Hero'],
            [
                'composition_scope' => 'hero',
                'design_dependencies' => ['requires_theme_research' => true],
            ],
        ),
        awpt_discovery_call(
            'awpt/read-pattern',
            ['purpose' => 'CTA'],
            [
                'composition_scope' => 'cta',
                'design_dependencies' => ['requires_theme_research' => true],
            ],
        ),
    ];
    $decision = new DiscoveryPolicy()->decide(
        'Create a page using images from my media library.',
        $calls,
        array_slice($calls, -3),
        35,
        ['content_turn' => true],
    );

    Assert::true($decision['compose'], 'multiple labelled pattern variants should transition to action');
}

function test_discovery_policy_composes_immediately_from_staged_revision_context(): void {
    $calls = [awpt_discovery_call(
        'awpt/read-proposal',
        ['action_id' => 9],
        [
            'revision_context' => [
                'action_id' => 9,
                'mode' => 'path_updates',
                'editable_slots' => [['block_path' => '0', 'current_text' => 'Commander News']],
            ],
        ],
    )];
    $decision = new DiscoveryPolicy()->decide('Make that Commander page look good.', $calls, $calls, 2, [
        'content_turn' => true,
    ]);

    Assert::true($decision['compose'], 'a verified staged proposal should not trigger site rediscovery');
    Assert::true(
        in_array('proposal_revision', $decision['coverage'], true),
        'revision context should be tracked as complete composition evidence',
    );
}

function test_presentation_edit_requires_structure_and_render_attempt(): void {
    $analysis = [awpt_discovery_call('awpt/analyze-page')];
    $incomplete = new DiscoveryPolicy()->decide('Make this page more presentable.', $analysis, $analysis, 5, [
        'content_turn' => true,
        'presentation_edit' => true,
    ]);

    Assert::false($incomplete['compose'], 'presentation edits should wait for a render inspection attempt');

    $calls = [
        ...$analysis,
        [
            'tool' => 'awpt/inspect-rendered-element',
            'input' => ['post_id' => 594, 'include_screenshot' => true],
            'output' => ['rendered' => false, 'warning' => 'headless_browser_unavailable'],
            'status' => 'success',
        ],
    ];
    $complete = new DiscoveryPolicy()->decide('Make this page more presentable.', $calls, [end($calls)], 8, [
        'content_turn' => true,
        'presentation_edit' => true,
    ]);

    Assert::true($complete['compose'], 'static render fallback still completes presentation discovery');
    Assert::true(in_array('page_analysis', $complete['coverage'], true), 'page analysis should be tracked');
    Assert::true(
        in_array('rendered_inspection', $complete['coverage'], true),
        'render inspection attempt should be tracked',
    );
}

function test_presentation_edit_accepts_complete_block_tree_as_structural_analysis(): void {
    $calls = [
        awpt_discovery_call('awpt/read-block-tree'),
        [
            'tool' => 'awpt/inspect-rendered-element',
            'input' => ['post_id' => 580, 'include_screenshot' => true],
            'output' => ['rendered' => false, 'warning' => 'headless_browser_unavailable'],
            'status' => 'success',
        ],
    ];
    $decision = new DiscoveryPolicy()->decide('Make this page more presentable.', $calls, $calls, 5, [
        'content_turn' => true,
        'presentation_edit' => true,
    ]);

    Assert::true(
        $decision['compose'],
        'a complete block-tree read should satisfy structural inspection without a redundant analyze-page call',
    );
}

test_presentation_edit_accepts_complete_block_tree_as_structural_analysis();

function test_presentation_edit_accepts_equivalent_complete_content_reads(): void {
    foreach (['awpt/list-blocks', 'awpt/read-content'] as $structural_tool) {
        $calls = [
            awpt_discovery_call($structural_tool),
            [
                'tool' => 'awpt/inspect-rendered-element',
                'input' => ['post_id' => 580, 'include_screenshot' => true],
                'output' => ['rendered' => false, 'warning' => 'headless_browser_unavailable'],
                'status' => 'success',
            ],
        ];
        $decision = new DiscoveryPolicy()->decide('Make this page more presentable.', $calls, $calls, 5, [
            'content_turn' => true,
            'presentation_edit' => true,
        ]);

        Assert::true(
            $decision['compose'],
            $structural_tool . ' should satisfy structural inspection without redundant analysis calls',
        );
    }
}

test_presentation_edit_accepts_equivalent_complete_content_reads();

function test_presentation_edit_reads_recommended_pattern_before_composing(): void {
    $calls = [
        awpt_discovery_call('awpt/read-content'),
        [
            'tool' => 'awpt/inspect-rendered-element',
            'input' => ['post_id' => 580, 'include_screenshot' => true],
            'output' => ['rendered' => false, 'warning' => 'headless_browser_unavailable'],
            'status' => 'success',
        ],
        [
            'tool' => 'awpt/recommend-patterns',
            'input' => ['intent' => 'documentation guide'],
            'output' => ['recommendations' => [['pattern' => ['name' => 'civicpress/layout-page-documentation']]]],
            'status' => 'success',
        ],
    ];
    $unread = new DiscoveryPolicy()->decide('Make this page more presentable.', $calls, $calls, 5, [
        'content_turn' => true,
        'presentation_edit' => true,
    ]);
    $calls[] = [
        'tool' => 'awpt/read-pattern',
        'input' => ['name' => 'civicpress/layout-page-documentation'],
        'output' => ['name' => 'civicpress/layout-page-documentation', 'composition_scope' => 'layout'],
        'status' => 'success',
    ];
    $read = new DiscoveryPolicy()->decide('Make this page more presentable.', $calls, [end($calls)], 8, [
        'content_turn' => true,
        'presentation_edit' => true,
    ]);

    Assert::false($unread['compose'], 'pattern metadata alone must not authorize an attributed layout rewrite');
    Assert::true($read['compose'], 'reading the selected recommended pattern should unlock composition');
}

test_presentation_edit_reads_recommended_pattern_before_composing();

test_discovery_policy_composes_after_required_page_evidence();
test_discovery_policy_allows_purposeful_theme_research_that_adds_evidence();
test_discovery_policy_immediately_composes_when_requested_media_is_verified();
test_discovery_policy_stops_exhausted_refinement();
test_discovery_policy_does_not_treat_many_purpose_labels_as_deeper_research();
test_discovery_policy_composes_immediately_from_staged_revision_context();
test_presentation_edit_requires_structure_and_render_attempt();
