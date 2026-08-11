<?php

/**
 * M5 Improve scorecard aggregation.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\QueueImproveScorecard;

function test_scorecard_from_run_detects_prepare_replace_and_freehand(): void {
    $card = new QueueImproveScorecard();

    $freehand = $card->from_run_summary([
        'post_id' => 848,
        'path_used' => 'pattern_provenance_freehand',
        'server_materialized' => false,
        'elapsed_s' => 12.5,
        'first_proposal_valid' => true,
        'meta' => ['prompt_version' => 'improve-page-v2'],
        'tools' => [
            'awpt/read-block-tree:success',
            'awpt/recommend-patterns:success',
            'awpt/read-pattern:success',
            'awpt/propose-content-update:success',
        ],
        'turn_outcome' => ['status' => 'staged'],
    ]);

    Assert::true($freehand['eligible_structural'], 'improve-page is structural');
    Assert::same(0, $freehand['prepare_change_success'], 'no prepare');
    Assert::same(0, $freehand['propose_replace_success'], 'no replace');
    Assert::true($freehand['freehand_provenance'], 'freehand path');
    Assert::same(12.5, $freehand['wall_s'], 'wall clock');

    $replace = $card->from_run_summary([
        'post_id' => 900,
        'path_used' => 'pattern_replace',
        'server_materialized' => true,
        'meta' => ['prompt_version' => 'improve-page-v2'],
        'tools' => [
            'awpt/prepare-pattern-change:success',
            'awpt/propose-pattern-replace:success',
        ],
        'first_proposal_valid' => true,
    ]);

    Assert::same(1, $replace['prepare_change_success'], 'prepare ok');
    Assert::same(1, $replace['propose_replace_success'], 'replace ok');
    Assert::false($replace['freehand_provenance'], 'not freehand');
    Assert::true($replace['server_materialized'], 'materialized');
}

function test_scorecard_aggregate_rates_use_denominators(): void {
    $card = new QueueImproveScorecard();
    $rows = [
        $card->from_run_summary([
            'post_id' => 1,
            'path_used' => 'pattern_provenance_freehand',
            'server_materialized' => false,
            'meta' => ['prompt_version' => 'improve-page-v2'],
            'tools' => ['awpt/read-block-tree:success'],
            'first_proposal_valid' => true,
            'elapsed_s' => 10,
        ]),
        $card->from_run_summary([
            'post_id' => 2,
            'path_used' => 'pattern_replace',
            'server_materialized' => true,
            'meta' => ['prompt_version' => 'improve-page-v2'],
            'tools' => [
                'awpt/prepare-pattern-change:success',
                'awpt/propose-pattern-replace:success',
            ],
            'first_proposal_valid' => true,
            'elapsed_s' => 20,
        ]),
        $card->from_run_summary([
            'post_id' => 3,
            'path_used' => 'no_change',
            'server_materialized' => false,
            'meta' => ['prompt_version' => 'improve-page-v2'],
            'tools' => ['awpt/analyze-page:success'],
            'first_proposal_valid' => null,
            'elapsed_s' => 30,
        ]),
    ];

    $agg = $card->aggregate($rows, ['label' => 'unit-test', 'note' => 'fixture']);

    Assert::same(3, $agg['n'], 'n=3');
    Assert::same(3, $agg['n_structural_eligible'], 'all structural');
    Assert::same(1, $agg['structural']['counts']['server_materialized'] ?? null, 'one materialized');
    Assert::same(1, $agg['structural']['counts']['prepare_change_success'] ?? null, 'one prepare');
    Assert::same(1, $agg['structural']['counts']['propose_replace_success'] ?? null, 'one replace');
    Assert::same(1, $agg['structural']['counts']['freehand_provenance'] ?? null, 'one freehand');
    Assert::same(0.3333, $agg['structural']['rates']['server_materialized']['rate'] ?? null, 'materialize rate 1/3');
    Assert::same(3, $agg['structural']['rates']['server_materialized']['denominator'] ?? null, 'denom 3');
    Assert::same(20.0, $agg['wall_s_mean'] ?? null, 'mean wall 20');
    Assert::true(str_contains((string) ($agg['policy'] ?? ''), 'report-only'), 'policy present');
}

function test_scorecard_resolve_input_paths_filters_raw_and_cohort(): void {
    $card = new QueueImproveScorecard();
    $dir = sys_get_temp_dir() . '/awpt-scorecard-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/awpt-queue-1.json', '{}');
    file_put_contents($dir . '/awpt-queue-1.raw.json', '{}');
    file_put_contents($dir . '/awpt-queue-1.pre-m2.json', '{}');
    file_put_contents($dir . '/cohort-x-summary.json', '{}');
    file_put_contents($dir . '/awpt-queue-2.json', '{}');

    $paths = $card->resolve_input_paths([$dir]);
    $bases = array_map('basename', $paths);
    sort($bases);

    Assert::same(['awpt-queue-1.json', 'awpt-queue-2.json'], $bases, 'only clean summaries');

    unlink($dir . '/awpt-queue-1.json');
    unlink($dir . '/awpt-queue-1.raw.json');
    unlink($dir . '/awpt-queue-1.pre-m2.json');
    unlink($dir . '/cohort-x-summary.json');
    unlink($dir . '/awpt-queue-2.json');
    rmdir($dir);
}

function test_scorecard_from_files_aggregates_disk_summaries(): void {
    $card = new QueueImproveScorecard();
    $dir = sys_get_temp_dir() . '/awpt-scorecard-files-' . bin2hex(random_bytes(4));
    mkdir($dir);

    file_put_contents($dir . '/awpt-queue-10.json', wp_json_encode([
        'post_id' => 10,
        'path_used' => 'pattern_provenance_freehand',
        'server_materialized' => false,
        'meta' => ['prompt_version' => 'improve-page-v2'],
        'tools' => ['awpt/read-pattern:success', 'awpt/propose-content-update:success'],
        'first_proposal_valid' => true,
        'elapsed_s' => 5,
    ]));
    file_put_contents($dir . '/awpt-queue-11.json', wp_json_encode([
        'post_id' => 11,
        'path_used' => 'pattern_replace',
        'server_materialized' => true,
        'meta' => ['prompt_version' => 'improve-page-v2'],
        'tools' => ['awpt/prepare-pattern-change:success', 'awpt/propose-pattern-replace:success'],
        'first_proposal_valid' => true,
        'elapsed_s' => 7,
    ]));

    $agg = $card->from_files([
        $dir . '/awpt-queue-10.json',
        $dir . '/awpt-queue-11.json',
    ], ['label' => 'disk']);

    Assert::same(2, $agg['n'], 'two files');
    Assert::same(1, $agg['structural']['counts']['propose_replace_success'] ?? null, 'one replace from disk');
    Assert::same('disk', $agg['label'] ?? null, 'label');

    unlink($dir . '/awpt-queue-10.json');
    unlink($dir . '/awpt-queue-11.json');
    rmdir($dir);
}

function test_scorecard_v2_uses_declared_classes_and_insert_funnel(): void {
    $card = new QueueImproveScorecard();
    $copy = $card->from_run_summary([
        'run_id' => 'copy-1',
        'scenario_class' => 'surgical_copy',
        'meta' => ['prompt_version' => 'improve-page-eval-act-v1'],
        'tools' => ['awpt/propose-block-batch-update:success'],
    ]);
    Assert::false($copy['eligible_structural'], 'declared copy task is not structural');
    Assert::same('declared_surgical_copy', $copy['eligibility_reason'] ?? null, 'class controls denominator');

    $insert = $card->from_run_summary([
        'run_id' => 'insert-1',
        'scenario_class' => 'additive_insert',
        'path_used' => 'pattern_insert',
        'server_materialized' => true,
        'tools' => [
            'awpt/prepare-pattern-change:success',
            'awpt/propose-pattern-insert:success',
        ],
    ]);
    Assert::true($insert['eligible_structural'], 'declared insert is structural');
    Assert::same(1, $insert['propose_insert_success'] ?? null, 'insert tracked separately');
    Assert::same('server_materialized', $insert['funnel_stage'] ?? null, 'funnel reaches materialization');
    Assert::same('insert-1', $insert['run_id'] ?? null, 'unique run retained');

    $failed = $card->from_run_summary([
        'scenario_class' => 'structural_replace',
        'tools' => [
            'awpt/prepare-pattern-change:success',
            'awpt/propose-pattern-replace:error',
        ],
    ]);
    Assert::same('proposal_failed', $failed['funnel_stage'] ?? null, 'failed proposal stage is explicit');

    $corrected = $card->from_run_summary([
        'scenario_class' => 'additive_insert',
        'path_used' => 'pattern_insert',
        'server_materialized' => true,
        'tools' => [
            'awpt/prepare-pattern-change:success',
            'awpt/propose-pattern-insert:error',
            'awpt/propose-pattern-insert:success',
        ],
    ]);
    Assert::same(1, $corrected['correction_count'] ?? null, 'failed compact proposal counts as correction');
    Assert::same('prepared_then_corrected', $corrected['funnel_stage'] ?? null, 'recovered proposal is explicit');
}

test_scorecard_from_run_detects_prepare_replace_and_freehand();
test_scorecard_aggregate_rates_use_denominators();
test_scorecard_resolve_input_paths_filters_raw_and_cohort();
test_scorecard_from_files_aggregates_disk_summaries();
test_scorecard_v2_uses_declared_classes_and_insert_funnel();
