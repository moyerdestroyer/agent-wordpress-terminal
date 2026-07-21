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
        'blocks' => [[
            'blockName' => 'core/group',
            'attrs' => ['layout' => ['type' => 'constrained']],
            'innerHTML' => '',
            'innerBlocks' => [[
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>Pattern body</p>',
                'innerBlocks' => [],
            ]],
        ]],
        'inserted_paths' => ['1', 'bad<script>'],
    ]);

    Assert::same(
        ActionOperations::PATTERN_INSERT,
        $payload['operation'] ?? null,
        'pattern operation should survive sanitization',
    );
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
}

test_pattern_action_payload_preserves_nested_composition();

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
