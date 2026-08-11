<?php

/**
 * Tests pattern composition payload storage.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Database\ActionPayloadSanitizer;
use AWPT\Support\ActionOperations;

function test_pattern_action_payload_preserves_nested_composition(): void {
    $payload = new ActionPayloadSanitizer()->sanitize([
        'operation' => ActionOperations::PATTERN_INSERT,
        'post_id' => 41,
        'pattern_name' => 'theme/hero',
        'pattern_title' => 'Hero',
        'block_path' => '',
        'position' => 'append',
        'preparation_id' => 'insert-prep-1',
        'blocks' => [[
            'blockName' => 'core/group',
            'attrs' => ['layout' => ['type' => 'constrained']],
            'innerHTML' => '<div class="wp-block-group"></div>',
            'innerBlocks' => [[
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>Pattern body</p>',
                'innerBlocks' => [],
                'innerContent' => ['<p>Pattern body</p>'],
            ]],
            'innerContent' => [
                '<div class="wp-block-group">',
                null,
                '</div>',
            ],
        ]],
        'inserted_paths' => ['1', 'bad<script>'],
    ]);

    Assert::same(
        ActionOperations::PATTERN_INSERT,
        $payload['operation'] ?? null,
        'pattern operation should survive sanitization',
    );
    Assert::same('insert-prep-1', $payload['preparation_id'] ?? null, 'insert preparation provenance survives');
    Assert::same('core/group', $payload['blocks'][0]['blockName'] ?? null, 'outer pattern block should be stored');
    Assert::same(
        'core/paragraph',
        $payload['blocks'][0]['innerBlocks'][0]['blockName'] ?? null,
        'nested pattern blocks should remain structured for safe reapplication',
    );
    Assert::same(
        ['1'],
        $payload['inserted_paths'] ?? null,
        'stored insertion paths should be valid dotted block paths',
    );
    Assert::same(
        ['<div class="wp-block-group">', null, '</div>'],
        $payload['blocks'][0]['innerContent'] ?? null,
        'container wrapper HTML in innerContent must survive sanitization for apply rebuild',
    );
}

function test_pattern_replace_payload_preserves_wrappers_for_apply_rebuild(): void {
    $payload = new ActionPayloadSanitizer()->sanitize([
        'operation' => ActionOperations::PATTERN_REPLACE,
        'post_id' => 55,
        'pattern_name' => 'theme/staff',
        'block_path' => '1',
        'expected_fingerprint' => str_repeat('e', 64),
        'blocks' => [[
            'blockName' => 'core/group',
            'attrs' => ['layout' => ['type' => 'constrained']],
            'innerHTML' => '<div class="wp-block-group is-layout-constrained"></div>',
            'innerBlocks' => [[
                'blockName' => 'core/heading',
                'attrs' => ['level' => 2],
                'innerHTML' => '<h2>Staff</h2>',
                'innerBlocks' => [],
                'innerContent' => ['<h2>Staff</h2>'],
            ]],
            'innerContent' => [
                '<div class="wp-block-group is-layout-constrained">',
                null,
                '</div>',
            ],
        ]],
    ]);

    Assert::same(ActionOperations::PATTERN_REPLACE, $payload['operation'] ?? null, 'replace op survives');
    Assert::true(
        is_array($payload['blocks'][0]['innerContent'] ?? null)
        && in_array(null, $payload['blocks'][0]['innerContent'], true)
        && str_contains((string) ($payload['blocks'][0]['innerContent'][0] ?? ''), 'wp-block-group'),
        'replace payload must keep wrapper fragments so apply matches preview',
    );
}

test_pattern_action_payload_preserves_nested_composition();
test_pattern_replace_payload_preserves_wrappers_for_apply_rebuild();

function test_action_payload_preserves_sanitized_markup_repair_report(): void {
    $payload = new ActionPayloadSanitizer()->sanitize([
        'operation' => ActionOperations::NEW_POST,
        'post_id' => 91,
        'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
        'repairs_applied' => [[
            'kind' => 'Wrapper Tag Alignment!',
            'block_path' => '2.0<script>',
            'block_name' => 'core/group<script>',
            'description' => 'Recorded tagName "section" to match the saved wrapper.',
        ]],
    ]);

    Assert::same(
        'wrappertagalignment',
        $payload['repairs_applied'][0]['kind'] ?? null,
        'repair kinds should be stored as safe machine-readable keys',
    );
    Assert::same(
        'Recorded tagName "section" to match the saved wrapper.',
        $payload['repairs_applied'][0]['description'] ?? null,
        'repair descriptions should remain available after payload sanitization',
    );
}

test_action_payload_preserves_sanitized_markup_repair_report();
