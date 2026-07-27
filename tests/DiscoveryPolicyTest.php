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
        true,
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
    $decision = new DiscoveryPolicy()->decide('Create a landing page.', $calls, [end($calls)], 25, true);

    Assert::false($decision['compose'], 'novel purpose-driven research should remain open');
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
    $decision = new DiscoveryPolicy()->decide('Create a landing page.', $calls, [end($calls)], 25, true);

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
        true,
    );

    Assert::true($decision['compose'], 'multiple labelled pattern variants should transition to action');
}

test_discovery_policy_composes_after_required_page_evidence();
test_discovery_policy_allows_purposeful_theme_research_that_adds_evidence();
test_discovery_policy_stops_exhausted_refinement();
test_discovery_policy_does_not_treat_many_purpose_labels_as_deeper_research();
