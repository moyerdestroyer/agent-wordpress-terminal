<?php

/**
 * Proposal failure constraint normalization and merged recovery guidance.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ProposalConstraintSet;
use AWPT\Agent\ProposalFailureNormalizer;

function test_proposal_failure_normalizer_maps_fingerprint_schema_like_mismatch(): void {
    $mismatch = ProposalFailureNormalizer::normalize('awpt_block_fingerprint_mismatch', [
        'block_path' => '0',
        'current_fingerprint' => str_repeat('a', 64),
        'remediation' => 'Copy current_fingerprint exactly.',
    ]);
    $schema = ProposalFailureNormalizer::normalize(
        'ability_invalid_input',
        [],
        'Ability "awpt/propose-block-batch-update" has invalid input. Reason: expected_fingerprint is a required property of input[changes][0].',
    );

    Assert::same('exact_fingerprints', $mismatch[0]['id'] ?? '', 'mismatch maps to fingerprint constraint');
    Assert::same(
        'exact_fingerprints',
        $schema[0]['id'] ?? '',
        'schema fingerprint failures share the same constraint family',
    );
    Assert::true(
        str_contains((string) ($schema[0]['hints'][0] ?? ''), '64 characters'),
        'schema failures should tell the model to copy fingerprints verbatim',
    );
}

test_proposal_failure_normalizer_maps_fingerprint_schema_like_mismatch();

function test_proposal_constraint_set_merges_content_loss_and_h1_without_forbidding_insert(): void {
    $set = new ProposalConstraintSet();
    $set->ingest([
        [
            'tool' => 'awpt/propose-content-update',
            'status' => 'failed',
            'output' => [
                'error_code' => 'awpt_presentation_content_loss',
                'error' => 'Preserve the source page.',
                'error_data' => [
                    'constraints' => ProposalFailureNormalizer::normalize('awpt_presentation_content_loss', ['missing_links' => [
                        'https://example.com/a',
                    ]]),
                ],
            ],
        ],
        [
            'tool' => 'awpt/propose-block-batch-update',
            'status' => 'failed',
            'output' => [
                'error_code' => 'awpt_required_page_h1_missing',
                'error' => 'Needs an H1.',
                'error_data' => [
                    'constraints' => ProposalFailureNormalizer::normalize('awpt_required_page_h1_missing'),
                ],
            ],
        ],
    ]);

    Assert::true($set->has('preserve_content'), 'content loss remains open');
    Assert::true($set->has('requires_page_h1'), 'H1 requirement remains open');

    $guidance = $set->recovery_guidance(2, 3);

    Assert::true(
        str_contains($guidance, 'awpt_presentation_content_loss'),
        'merged guidance must surface the content-loss code',
    );
    Assert::true(str_contains($guidance, 'awpt_required_page_h1_missing'), 'merged guidance must surface the H1 code');
    Assert::true(
        str_contains($guidance, 'Include exactly one content H1'),
        'H1 must remain an explicit compatible next step',
    );
    Assert::true(
        str_contains($guidance, 'An H1 promote/insert that reuses verified title text is allowed'),
        'escalated preserve must not cancel the open H1 insert',
    );
    Assert::false(
        str_contains($guidance, 'Do not use replace_text, remove, or insert'),
        'attrs-only insert ban must not apply while H1 insert is still required',
    );
}

test_proposal_constraint_set_merges_content_loss_and_h1_without_forbidding_insert();

function test_proposal_constraint_recovery_guidance_includes_facts_and_hints(): void {
    $set = new ProposalConstraintSet();
    $set->ingest([[
        'tool' => 'awpt/propose-content-update',
        'status' => 'failed',
        'output' => [
            'error_code' => 'awpt_presentation_content_loss',
            'error' => 'Preserve the source page.',
            'error_data' => [
                'constraints' => ProposalFailureNormalizer::normalize('awpt_presentation_content_loss', [
                    'token_recall' => 0.786,
                    'missing_excerpt' => 'Legislative Chair',
                    'missing_links' => ['https://example.com/statute'],
                ]),
            ],
        ],
    ]]);

    $guidance = $set->recovery_guidance(1, 3);

    Assert::true(str_contains($guidance, 'token_recall'), 'recovery should surface token_recall');
    Assert::true(str_contains($guidance, '0.786'), 'recovery should surface the recall value');
    Assert::true(
        str_contains($guidance, 'Legislative Chair') || str_contains($guidance, 'missing_excerpt'),
        'recovery should surface missing excerpt facts',
    );
    Assert::true(str_contains($guidance, 'hint:'), 'recovery should surface normalizer hints');
}

test_proposal_constraint_recovery_guidance_includes_facts_and_hints();

function test_proposal_constraint_set_escalates_content_loss_only_to_attrs_batch(): void {
    $set = new ProposalConstraintSet();
    $set->ingest([[
        'tool' => 'awpt/propose-content-update',
        'status' => 'failed',
        'output' => [
            'error_code' => 'awpt_presentation_content_loss',
            'error' => 'content loss',
            'error_data' => [],
        ],
    ]]);

    $first = $set->recovery_guidance(1, 3);
    $second = $set->recovery_guidance(2, 3);

    Assert::true(
        str_contains($first, 'may retry a full-document layout adaptation'),
        'first content-loss recovery keeps full-page correction',
    );
    Assert::true(
        str_contains($second, 'Use awpt/propose-block-batch-update now'),
        'repeated content loss alone escalates toward batch conservation',
    );
    Assert::true(
        str_contains($second, 'Do not use replace_text, remove, or insert'),
        'content-loss-only escalation may prefer attrs-only when H1 is not open',
    );
    Assert::false(
        str_contains($second, 'may retry a full-document layout adaptation'),
        'escalation should not keep pitching the same lossy full rewrite',
    );
}

test_proposal_constraint_set_escalates_content_loss_only_to_attrs_batch();

function test_proposal_constraint_set_pattern_read_short_circuits_proposal_retry(): void {
    $set = new ProposalConstraintSet();
    $set->ingest([[
        'tool' => 'awpt/propose-content-update',
        'status' => 'failed',
        'output' => [
            'error_code' => 'awpt_pattern_not_read',
            'error' => 'Read the pattern first.',
            'error_data' => [],
        ],
    ]]);

    $guidance = $set->recovery_guidance(1, 3);

    Assert::true(
        str_contains($guidance, 'Do not retry the proposal yet'),
        'unread pattern must block another propose hop',
    );
    Assert::true(str_contains($guidance, 'awpt/read-pattern'), 'guidance should point at read-pattern');
}

test_proposal_constraint_set_pattern_read_short_circuits_proposal_retry();

function test_proposal_failure_normalizer_maps_unresolved_local_media(): void {
    $constraints = ProposalFailureNormalizer::normalize(
        'awpt_unresolved_local_media_url',
        [
            'block_path' => '2.0.1.0.0',
            'block_name' => 'core/image',
            'url' => 'https://example.test/wp-content/uploads/2026/08/person-150x150.jpg',
            'recovery' => 'Omit optional images or use media_unavailable.',
        ],
        'Block references an unresolved Media Library URL.',
    );

    Assert::same('unresolved_local_media', $constraints[0]['id'] ?? '', 'media failures map to a dedicated constraint');
    Assert::true(
        str_contains(implode(' ', $constraints[0]['hints'] ?? []), 'media_unavailable')
            || str_contains(implode(' ', $constraints[0]['hints'] ?? []), 'Omit'),
        'hints should steer omit or media_unavailable',
    );
    Assert::true(
        str_contains(implode(' ', $constraints[0]['hints'] ?? []), 'recommend-patterns'),
        'hints should discourage recommend thrash after media failures',
    );
}

test_proposal_failure_normalizer_maps_unresolved_local_media();

function test_proposal_constraint_set_media_failure_avoids_recommend_thrash(): void {
    $set = new ProposalConstraintSet();
    $set->ingest([[
        'tool' => 'awpt/propose-content-update',
        'status' => 'failed',
        'output' => [
            'error_code' => 'awpt_unresolved_local_media_url',
            'error' => 'Unresolved media.',
            'error_data' => [
                'constraints' => ProposalFailureNormalizer::normalize('awpt_unresolved_local_media_url', [
                    'url' => 'https://example.test/wp-content/uploads/x.jpg',
                ]),
            ],
        ],
    ]]);

    $guidance = $set->recovery_guidance(1, 3);

    Assert::true(
        str_contains($guidance, 'unresolved_local_media') || str_contains($guidance, 'awpt_unresolved_local_media_url'),
        'media constraint should surface in recovery',
    );
    Assert::true(
        str_contains($guidance, 'Do not re-run recommend-patterns')
            || str_contains($guidance, 'do not re-call them'),
        'media recovery should discourage discovery thrash',
    );
}

test_proposal_constraint_set_media_failure_avoids_recommend_thrash();

function test_proposal_failure_normalizer_maps_replace_position_and_domain(): void {
    $replace = ProposalFailureNormalizer::normalize(
        'awpt_pattern_replace_requires_content',
        [
            'received_position' => 'replace',
            'allowed_positions' => ['before', 'after', 'append'],
        ],
        'position replace is not a block insert.',
    );
    Assert::same('pattern_insert_position', $replace[0]['id'] ?? '', 'replace maps to insert-position constraint');
    Assert::true(
        str_contains(implode(' ', $replace[0]['hints'] ?? []), 'propose-pattern-replace')
        || str_contains(implode(' ', $replace[0]['hints'] ?? []), 'prepare-pattern-change'),
        'replace recovery should point at prepare/propose pattern replace',
    );

    $domain = ProposalFailureNormalizer::normalize(
        'awpt_domain_validation_failed',
        [
            'blocking_findings' => [[
                'severity' => 'error',
                'code' => 'no-custom-html',
                'message' => 'Custom HTML not allowed on compose.',
            ]],
            'primary_code' => 'no-custom-html',
        ],
        'Custom HTML not allowed on compose.',
    );
    Assert::same('domain_validation', $domain[0]['id'] ?? '', 'domain failures map to domain_validation');
    Assert::true(
        str_contains(implode(' ', $domain[0]['hints'] ?? []), 'blocking_findings')
            || str_contains(implode(' ', $domain[0]['hints'] ?? []), 'Inherited'),
        'domain recovery should mention blocking vs inherited',
    );

    $timeout = ProposalFailureNormalizer::normalize('http_request_failed', [], 'cURL error 28');
    Assert::same('provider_timeout', $timeout[0]['id'] ?? '', 'timeouts map to provider_timeout');
    Assert::true(
        str_contains(implode(' ', $timeout[0]['hints'] ?? []), 'Shrink')
            || str_contains(implode(' ', $timeout[0]['hints'] ?? []), 'full'),
        'timeout recovery should ask for a smaller propose',
    );
}

test_proposal_failure_normalizer_maps_replace_position_and_domain();
