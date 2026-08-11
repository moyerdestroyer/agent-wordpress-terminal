<?php

/** Prepared insert schema and recovery contract tests. */

declare(strict_types=1);

use AWPT\Abilities\ProposePatternInsert;
use AWPT\Domain\PatternPreparationReceipt;

function test_prepared_insert_schema_accepts_compact_receipt_fields(): void {
    awpt_test_reset_state();
    new ProposePatternInsert()->register();
    $ability = wp_get_ability('awpt/propose-pattern-insert');
    Assert::true($ability instanceof WP_Ability, 'insert ability registered');
    $schema = $ability instanceof WP_Ability ? $ability->get_input_schema() : [];
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

    Assert::true(isset($properties['preparation_id']), 'prepared id accepted');
    Assert::true(isset($properties['pattern_text_updates']), 'compact text updates accepted');
    Assert::true(isset($properties['media_placements']), 'compact media placements accepted');
    Assert::false(in_array('pattern_name', $required, true), 'prepared mode derives pattern name from receipt');
}

function test_prepared_insert_slot_error_returns_bound_retry_context(): void {
    awpt_test_reset_state();
    $content =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2>Original heading</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $minted = new PatternPreparationReceipt()->mint([
        'post_id' => 848,
        'session_id' => 7,
        'mode' => PatternPreparationReceipt::MODE_INSERT,
        'target_path' => '1',
        'expected_fingerprint' => str_repeat('a', 64),
        'source_content_hash' => str_repeat('b', 64),
        'pattern_names' => ['theme/cta'],
        'expanded_content_hash' => hash('sha256', $content),
        'pattern_content' => $content,
        'position' => 'after',
        'carry_forward' => [['kind' => 'url', 'value' => 'https://example.test/contact']],
    ]);

    $method = new ReflectionMethod(ProposePatternInsert::class, 'prepared_slot_error');
    $error = $method->invoke(
        new ProposePatternInsert(),
        new WP_Error('awpt_pattern_text_block_not_editable', 'Not editable.', ['status' => 409, 'block_path' => '0']),
        $minted['receipt'],
    );
    Assert::true($error instanceof WP_Error, 'recovery remains a WordPress error');
    $data = $error instanceof WP_Error ? $error->get_error_data() : [];
    Assert::same($minted['preparation_id'], $data['preparation_id'] ?? null, 'same receipt returned');
    Assert::same('0.0', $data['editable_slots'][0]['block_path'] ?? null, 'allowed slot returned');
    Assert::same(
        'https://example.test/contact',
        $data['carry_forward'][0]['value'] ?? null,
        'carry-forward context retained',
    );
    Assert::true(str_contains((string) ($data['recovery'] ?? ''), 'Do not prepare again'), 'retry is bounded');
}

test_prepared_insert_schema_accepts_compact_receipt_fields();
test_prepared_insert_slot_error_returns_bound_retry_context();
