<?php

/**
 * Tests for Improve path classification (M0 audit honesty).
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\ActionOperations;
use AWPT\Support\QueueImprovePathClassifier;

function test_queue_path_classifier_marks_pattern_replace_as_server_materialized(): void {
    $result = new QueueImprovePathClassifier()->classify([
        [
            'operation' => ActionOperations::PATTERN_REPLACE,
            'pattern_name' => 'theme/staff',
            'composition_manifest' => [
                'patterns' => [['name' => 'theme/staff', 'mode' => 'replaced']],
            ],
        ],
    ], ['awpt/prepare-pattern-change:success']);

    Assert::same('pattern_replace', $result['path_used'], 'replace should be the primary path label');
    Assert::true($result['server_materialized'], 'pattern_replace is server-materialized');
    Assert::true(
        in_array(ActionOperations::PATTERN_REPLACE, $result['server_materialized_operations'], true),
        'materialized ops should include pattern_replace',
    );
}

function test_queue_path_classifier_does_not_treat_provenance_freehand_as_materialized(): void {
    $result = new QueueImprovePathClassifier()->classify([
        [
            'operation' => ActionOperations::CONTENT_UPDATE,
            'pattern_name' => 'theme/hero',
        ],
    ], ['awpt/read-pattern:success']);

    Assert::same(
        'pattern_provenance_freehand',
        $result['path_used'],
        'content_update with pattern_name after a read is provenance freehand',
    );
    Assert::false(
        $result['server_materialized'],
        'pattern_name alone must not count as server materialization',
    );
}

function test_queue_path_classifier_pattern_insert_is_materialized(): void {
    $result = new QueueImprovePathClassifier()->classify([
        ['operation' => ActionOperations::PATTERN_INSERT, 'pattern_name' => 'theme/cta'],
    ]);

    Assert::same('pattern_insert', $result['path_used'], 'insert path label');
    Assert::true($result['server_materialized'], 'insert is server-materialized');
}

test_queue_path_classifier_marks_pattern_replace_as_server_materialized();
test_queue_path_classifier_does_not_treat_provenance_freehand_as_materialized();
test_queue_path_classifier_pattern_insert_is_materialized();
