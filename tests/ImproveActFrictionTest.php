<?php

/**
 * Auto-prepare path inference and Improve-act propose hydration.
 */

declare(strict_types=1);

use AWPT\Database\ImproveWorkflowRepository;
use AWPT\Support\ImproveActProposeHydrator;
use AWPT\Support\PatternProposeAutoPrepare;

function test_pattern_propose_auto_prepare_path_first_errors_and_soft_paths(): void {
    $auto = new PatternProposeAutoPrepare();

    $missing = $auto->resolve([
        'post_id' => 828,
        'session_id' => 1,
        'title' => 'Replace FAQ root',
        'description' => 'Use documentation layout',
    ], 'replace');
    Assert::true(is_wp_error($missing), 'missing path fails');
    if (is_wp_error($missing)) {
        Assert::same('awpt_preparation_id_required', $missing->get_error_code(), 'path-required code');
        Assert::true(
            str_contains($missing->get_error_message(), 'path')
            && str_contains($missing->get_error_message(), 'intent'),
            'error leads with path and intent',
        );
        Assert::false(
            str_starts_with($missing->get_error_message(), 'Pass a real preparation_id'),
            'error must not lead with preparation_id',
        );
    }

    $ref = new ReflectionClass($auto);
    $normalize = $ref->getMethod('normalize_path_candidate');
    $normalize->setAccessible(true);
    Assert::same('0', $normalize->invoke($auto, '[0]'), 'bracketed path softens');
    Assert::same('2.1', $normalize->invoke($auto, 'path 2.1'), 'path prefix softens');
    Assert::same('document', $normalize->invoke($auto, 'document'), 'document alias stays explicit');
    Assert::same('', $normalize->invoke($auto, 'root'), 'ambiguous root alias is rejected');
    Assert::same('', $normalize->invoke($auto, '*'), 'star alias is rejected');
    Assert::same('', $normalize->invoke($auto, 'hero'), 'prose paths still rejected');

    $wants = $ref->getMethod('wants_entire_document');
    $wants->setAccessible(true);
    Assert::false($wants->invoke($auto, ['path' => '*']), 'star is not a document target');
    Assert::true($wants->invoke($auto, ['path' => 'document']), 'document wants entire document');
    Assert::false($wants->invoke($auto, ['path' => '0']), 'numeric path is section-scoped');

    $infer_path = $ref->getMethod('infer_path');
    $infer_path->setAccessible(true);
    Assert::same(
        '',
        $infer_path->invoke($auto, [
            'pattern_text_updates' => [['block_path' => '1.0.0.0.0', 'content' => 'x']],
        ]),
        'pattern-internal update paths must not become the page section path',
    );
}

function test_pattern_propose_auto_prepare_invented_uuid_falls_through_when_path_intent_present(): void {
    $auto = new PatternProposeAutoPrepare();
    $ref = new ReflectionClass($auto);
    $infer_path = $ref->getMethod('infer_path');
    $infer_path->setAccessible(true);
    $infer_intent = $ref->getMethod('infer_intent');
    $infer_intent->setAccessible(true);

    $input = [
        'preparation_id' => '5622f45e-e89b-4566-8a8d-1eed38a8de9b',
        'path' => '0',
        'intent' => 'documentation layout for FAQ',
        'post_id' => 828,
        'session_id' => 1,
    ];
    Assert::same('0', $infer_path->invoke($auto, $input), 'path inferred despite fake prep id');
    Assert::same(
        'documentation layout for FAQ',
        $infer_intent->invoke($auto, $input),
        'intent inferred despite fake prep id',
    );
    Assert::false($auto->looks_like_hash((string) $input['preparation_id']), 'uuid is not a hash');
}

function test_pattern_propose_auto_prepare_forwards_pattern_name(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 828;
    $post->post_type = 'page';
    $post->post_modified_gmt = '2026-01-01 00:00:00';
    $post->post_content =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2>FAQ</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $GLOBALS['awpt_test_posts'][828] = $post;
    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'demo/section-toc',
            'title' => 'TOC Section',
            'description' => 'Two column TOC',
            'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>TOC</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        ],
        [
            'name' => 'demo/layout-page-documentation',
            'title' => 'Documentation Layout',
            'description' => 'Full page docs',
            'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h1>Docs</h1><!-- /wp:heading --></div><!-- /wp:group -->',
        ],
    ];

    $receipt = new PatternProposeAutoPrepare()->resolve([
        'post_id' => 828,
        'session_id' => 0,
        'path' => '0',
        'intent' => 'documentation layout for FAQ',
        'pattern_name' => 'demo/layout-page-documentation',
        'title' => 'Replace root',
        'description' => 'Use docs layout',
    ], 'replace');

    Assert::false(is_wp_error($receipt), 'auto-prepare with named layout succeeds');
    Assert::true(is_array($receipt), 'receipt array');
    if (!is_array($receipt)) {
        return;
    }

    Assert::same(
        ['demo/layout-page-documentation'],
        $receipt['pattern_names'] ?? null,
        'receipt binds caller pattern_name, not section re-rank',
    );
}

function test_improve_act_propose_hydrator_fills_from_workflow_unit(): void {
    awpt_test_reset_state();
    $repository = new ImproveWorkflowRepository();
    $repository->begin_evaluate(801, 828, 'eval-hydrate');
    $ready = $repository->plan_ready(
        801,
        "## Plan\n\nUse documentation layout.\n\n```awpt-units\n"
        . '[{"id":"layout","title":"Doc layout","op":"pattern_replace","paths":["document"],'
        . '"pattern_name":"civicpress/layout-page-documentation",'
        . '"brief":"Replace root with documentation layout",'
        . '"expected_fingerprint":"'
        . str_repeat('b', 64)
        . '"}]'
        . "\n```",
        [
            ...awpt_test_improve_tree_evidence(828),
            [
                'tool' => 'awpt/recommend-patterns',
                'status' => 'success',
                'output' => [
                    'recommendations' => [[
                        'pattern' => ['name' => 'civicpress/layout-page-documentation'],
                        'rationale' => 'Fits long-form documentation.',
                    ]],
                ],
            ],
        ],
    );
    Assert::same('plan_ready', $ready['state'] ?? null, 'workflow ready for hydrate');
    $acting = $repository->begin_act(801, (string) $ready['id'], 828, 'act-hydrate');
    Assert::false(is_wp_error($acting), 'act begins');

    $hydrated = new ImproveActProposeHydrator()->hydrate(
        801,
        [
            'post_id' => 828,
            'title' => 'SLIP FAQ',
            'description' => 'Replace flat FAQ',
        ],
        'awpt/propose-pattern-replace',
    );

    Assert::same('document', $hydrated['path'] ?? null, 'document path filled from unit');
    Assert::same('document', $hydrated['target_path'] ?? null, 'document target_path filled from unit');
    Assert::true((bool) ($hydrated['replace_entire_document'] ?? false), 'document intent is explicit');
    Assert::same(
        'Replace root with documentation layout',
        $hydrated['intent'] ?? null,
        'intent filled from unit brief',
    );
    Assert::same(
        'civicpress/layout-page-documentation',
        $hydrated['pattern_name'] ?? null,
        'pattern_name filled from unit',
    );
    Assert::same(str_repeat('b', 64), $hydrated['expected_fingerprint'] ?? null, 'fingerprint filled');
    Assert::false(isset($hydrated['preparation_id']), 'hydrator never invents preparation_id');
}

function test_proposal_failure_heading_skip_hints_same_batch_fix(): void {
    $normalized = AWPT\Agent\ProposalFailureNormalizer::normalize(
        'awpt_heading_level_skipped',
        [],
        'The proposed page skips a heading level in its document outline.',
    );
    $hints = implode(' ', $normalized[0]['hints'] ?? []);
    Assert::true(
        str_contains($hints, 'same batch') || str_contains($hints, 'remaining'),
        'heading-skip recovery tells the model to fix remaining headings together',
    );
}

test_pattern_propose_auto_prepare_path_first_errors_and_soft_paths();
test_pattern_propose_auto_prepare_invented_uuid_falls_through_when_path_intent_present();
test_pattern_propose_auto_prepare_forwards_pattern_name();
test_improve_act_propose_hydrator_fills_from_workflow_unit();
test_proposal_failure_heading_skip_hints_same_batch_fix();
