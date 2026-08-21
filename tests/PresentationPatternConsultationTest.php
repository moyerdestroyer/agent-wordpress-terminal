<?php

/**
 * Pattern consultation helpers (advisory; no hard gates).
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PatternFirstPolicy;
use AWPT\Support\PresentationPatternConsultation;
use AWPT\Support\TurnToolEvidence;

function test_presentation_pattern_consultation_is_advisory(): void {
    $consultation = new PresentationPatternConsultation();
    $recommendations = [
        ['pattern' => ['name' => 'civicpress/section-team-member-directory']],
    ];

    Assert::true(
        $consultation->is_presentation_edit_message('Improve this focused page so it is clearer and more presentable.'),
        'guided improve brief should classify as redesign/presentation edit',
    );
    Assert::true(
        $consultation->is_presentation_edit_message('Redesign this focused page using active-theme patterns.'),
        'short redesign brief should classify as redesign',
    );
    unset($recommendations);
}

function test_pattern_first_uses_in_request_turn_tool_evidence(): void {
    $session_id = 990539;
    TurnToolEvidence::reset($session_id);

    Assert::same(
        [],
        new PatternFirstPolicy()->nonempty_recommendations($session_id),
        'empty turn + empty DB should yield no recommendations',
    );

    TurnToolEvidence::record($session_id, [
        'tool' => 'awpt/recommend-patterns',
        'status' => 'success',
        'input' => ['intent' => 'committee roster'],
        'output' => [
            'recommendations' => [
                ['pattern' => ['name' => 'civicpress/layout-page-cards', 'title' => 'Cards Page']],
            ],
        ],
    ]);

    $recs = new PatternFirstPolicy()->nonempty_recommendations($session_id);
    Assert::true([] !== $recs, 'in-request recommend-patterns should be visible mid-turn');
    Assert::same(
        'civicpress/layout-page-cards',
        (string) ($recs[0]['pattern']['name'] ?? ''),
        'turn evidence recommendation slug should surface',
    );

    TurnToolEvidence::reset($session_id);
}

test_presentation_pattern_consultation_is_advisory();
test_pattern_first_uses_in_request_turn_tool_evidence();
