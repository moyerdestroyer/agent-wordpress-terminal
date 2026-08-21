<?php

/**
 * Pattern structure evidence and unfit honesty gates.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Domain\PatternStructureEvidence;
use AWPT\Support\PatternFirstPolicy;
use AWPT\Support\TurnToolEvidence;

function test_pattern_structure_evidence_requires_read_for_named_pattern(): void {
    awpt_test_reset_state();
    TurnToolEvidence::reset();
    $session_id = 42;
    $evidence = new PatternStructureEvidence();

    Assert::true(
        null !== $evidence->require_read_for_pattern_name($session_id, 'fixture/header-page'),
        'missing read must error',
    );

    TurnToolEvidence::record($session_id, [
        'tool' => 'awpt/read-pattern',
        'input' => ['name' => 'fixture/header-page'],
        'output' => ['name' => 'fixture/header-page'],
        'status' => 'success',
    ]);

    Assert::true(
        $evidence->has_read($session_id, 'fixture/header-page'),
        'read-pattern should count as structure evidence',
    );
    Assert::true(
        null === $evidence->require_read_for_pattern_name($session_id, 'fixture/header-page'),
        'after read, pattern claim is allowed',
    );

    TurnToolEvidence::reset($session_id);
}

function test_pattern_structure_evidence_rejects_dishonest_no_recommendations(): void {
    awpt_test_reset_state();
    TurnToolEvidence::reset();
    $session_id = 7;
    TurnToolEvidence::record($session_id, [
        'tool' => 'awpt/recommend-patterns',
        'input' => ['intent' => 'faq'],
        'output' => [
            'recommendations' => [
                ['pattern' => ['name' => 'fixture/header-page']],
            ],
        ],
        'status' => 'success',
    ]);

    $error = new PatternStructureEvidence()->validate_unfit_code(
        $session_id,
        PatternFirstPolicy::CODE_NO_RECOMMENDATIONS,
    );

    Assert::true($error instanceof WP_Error, 'no_recommendations with nonempty recommend is dishonest');
    Assert::same(
        'awpt_pattern_unfit_dishonest',
        $error instanceof WP_Error ? $error->get_error_code() : '',
        'error code for dishonest unfit',
    );

    Assert::true(
        null === new PatternStructureEvidence()->validate_unfit_code(
            $session_id,
            PatternFirstPolicy::CODE_SCOPE_MISMATCH,
        ),
        'other unfit codes remain allowed',
    );

    TurnToolEvidence::reset($session_id);
}

function test_pattern_structure_evidence_allows_no_recommendations_when_empty(): void {
    awpt_test_reset_state();
    TurnToolEvidence::reset();
    $session_id = 8;
    TurnToolEvidence::record($session_id, [
        'tool' => 'awpt/recommend-patterns',
        'input' => ['intent' => 'faq'],
        'output' => ['recommendations' => []],
        'status' => 'success',
    ]);

    Assert::true(
        null === new PatternStructureEvidence()->validate_unfit_code(
            $session_id,
            PatternFirstPolicy::CODE_NO_RECOMMENDATIONS,
        ),
        'empty recommend allows no_recommendations code',
    );

    TurnToolEvidence::reset($session_id);
}

function test_pattern_structure_evidence_accepts_prepare_draft(): void {
    awpt_test_reset_state();
    TurnToolEvidence::reset();
    $session_id = 9;
    TurnToolEvidence::record($session_id, [
        'tool' => 'awpt/prepare-pattern-draft',
        'input' => ['intent' => 'landing'],
        'output' => [
            'mode' => 'pattern',
            'pattern' => [
                'name' => 'fixture/layout-page-basic',
                'pattern_names' => ['fixture/layout-page-basic', 'fixture/header-page'],
                'components' => [
                    ['name' => 'fixture/layout-page-basic'],
                    ['name' => 'fixture/section-text-one-column'],
                ],
            ],
        ],
        'status' => 'success',
    ]);

    $evidence = new PatternStructureEvidence();
    Assert::true(
        $evidence->has_read($session_id, 'fixture/header-page'),
        'nested prepare-pattern-draft pattern_names count as structure reads',
    );
    Assert::true(
        $evidence->has_read($session_id, 'fixture/section-text-one-column'),
        'prepare draft components should count as structure evidence',
    );
    Assert::true($evidence->session_has_pattern_draft($session_id), 'prepare draft mode=pattern should be detected');

    TurnToolEvidence::reset($session_id);
}

test_pattern_structure_evidence_requires_read_for_named_pattern();
test_pattern_structure_evidence_rejects_dishonest_no_recommendations();
test_pattern_structure_evidence_allows_no_recommendations_when_empty();
test_pattern_structure_evidence_accepts_prepare_draft();
