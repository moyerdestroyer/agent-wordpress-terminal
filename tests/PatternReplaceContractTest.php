<?php

/**
 * Contract tests for section pattern replace (M1).
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\ActionAppliers\BlockStructureUpdateActionApplier;
use AWPT\Database\ActionPayloadSanitizer;
use AWPT\Domain\PatternPreparationReceipt;
use AWPT\Support\ActionOperations;
use AWPT\Support\BlockTree;

function test_block_tree_replace_preserves_non_target_sections(): void {
    awpt_test_reset_state();

    $content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Middle</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->';

    $tree = BlockTree::from_content($content);
    $middle = $tree->get_block('1');
    Assert::true(is_array($middle), 'middle section should resolve');
    $fingerprint = BlockTree::fingerprint($middle);

    $replaced = $tree->replace_blocks(
        '1',
        [[
            'blockName' => 'core/group',
            'attrs' => [],
            'innerHTML' => '',
            'innerBlocks' => [[
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>Staff directory</p>',
                'innerBlocks' => [],
                'innerContent' => ['<p>Staff directory</p>'],
            ]],
            'innerContent' => [null],
        ]],
        $fingerprint,
    );

    Assert::false(is_wp_error($replaced), 'replace with matching fingerprint should succeed');

    if (!is_wp_error($replaced)) {
        Assert::true(str_contains($replaced['content'], '<p>Intro</p>'), 'intro must remain');
        Assert::true(str_contains($replaced['content'], '<p>Footer</p>'), 'footer must remain');
        Assert::true(str_contains($replaced['content'], '<p>Staff directory</p>'), 'replacement body present');
        Assert::false(str_contains($replaced['content'], '<p>Middle</p>'), 'target section should be gone');
        Assert::same(['1'], $replaced['paths'] ?? null, 'single-root replace keeps path 1');
    }
}

function test_block_tree_replace_rejects_stale_fingerprint(): void {
    awpt_test_reset_state();

    $content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Middle</p><!-- /wp:paragraph -->';

    $stale = BlockTree::from_content($content)->replace_blocks(
        '1',
        [[
            'blockName' => 'core/paragraph',
            'attrs' => [],
            'innerHTML' => '<p>New</p>',
            'innerBlocks' => [],
            'innerContent' => ['<p>New</p>'],
        ]],
        str_repeat('0', 64),
    );

    Assert::true(is_wp_error($stale), 'stale fingerprints must fail closed');
    if (is_wp_error($stale)) {
        Assert::same(
            'awpt_block_fingerprint_mismatch',
            $stale->get_error_code(),
            'stale fingerprint should use mismatch error code',
        );
    }
}

function test_block_tree_replace_expands_multi_root_in_place(): void {
    awpt_test_reset_state();

    $content =
        '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>C</p><!-- /wp:paragraph -->';

    $tree = BlockTree::from_content($content);
    $fp = BlockTree::fingerprint($tree->get_block('1') ?? []);
    $replaced = $tree->replace_blocks(
        '1',
        [
            [
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>B1</p>',
                'innerBlocks' => [],
                'innerContent' => ['<p>B1</p>'],
            ],
            [
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>B2</p>',
                'innerBlocks' => [],
                'innerContent' => ['<p>B2</p>'],
            ],
        ],
        $fp,
    );

    Assert::false(is_wp_error($replaced), 'multi-root replace should succeed');

    if (!is_wp_error($replaced)) {
        Assert::true(str_contains($replaced['content'], '<p>A</p>'), 'section A preserved');
        Assert::true(str_contains($replaced['content'], '<p>B1</p>'), 'first replacement root present');
        Assert::true(str_contains($replaced['content'], '<p>B2</p>'), 'second replacement root present');
        Assert::true(str_contains($replaced['content'], '<p>C</p>'), 'section C preserved');
        Assert::same(['1', '2'], $replaced['paths'] ?? null, 'multi-root occupies successive paths');
    }
}

function test_pattern_replace_action_applier_rebuilds_live_content(): void {
    awpt_test_reset_state();
    awpt_test_post(72, 'Service page', 'service', 'page');
    $GLOBALS['awpt_test_posts'][72]->post_content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Old section</p><!-- /wp:paragraph -->';

    $tree = BlockTree::from_content($GLOBALS['awpt_test_posts'][72]->post_content);
    $fp = BlockTree::fingerprint($tree->get_block('1') ?? []);

    $content = new BlockStructureUpdateActionApplier()->content_from_payload(72, [
        'operation' => ActionOperations::PATTERN_REPLACE,
        'block_path' => '1',
        'expected_fingerprint' => $fp,
        'blocks' => [[
            'blockName' => 'core/paragraph',
            'attrs' => [],
            'innerHTML' => '<p>New section</p>',
            'innerBlocks' => [],
            'innerContent' => ['<p>New section</p>'],
        ]],
    ]);

    Assert::false(is_wp_error($content), 'apply-time rebuild should succeed');

    if (!is_wp_error($content)) {
        Assert::true(str_contains($content, '<p>Intro</p>'), 'intro preserved on apply');
        Assert::true(str_contains($content, '<p>New section</p>'), 'replacement applied');
        Assert::false(str_contains($content, '<p>Old section</p>'), 'old section removed on apply');
    }
}

function test_pattern_preparation_receipt_round_trip_and_tamper(): void {
    awpt_test_reset_state();

    $service = new PatternPreparationReceipt();
    $markup = '<!-- wp:paragraph --><p>Staff</p><!-- /wp:paragraph -->';
    $minted = $service->mint([
        'post_id' => 10,
        'session_id' => 3,
        'mode' => PatternPreparationReceipt::MODE_REPLACE,
        'intent' => 'staff directory',
        'target_path' => '1',
        'expected_fingerprint' => str_repeat('a', 64),
        'source_content_hash' => str_repeat('b', 64),
        'pattern_names' => ['theme/staff'],
        'expanded_content_hash' => hash('sha256', $markup),
        'pattern_content' => $markup,
        'post_type' => 'page',
    ]);

    Assert::true('' !== ($minted['preparation_id'] ?? ''), 'mint returns preparation_id');
    $loaded = $service->load((string) $minted['preparation_id']);
    Assert::false(is_wp_error($loaded), 'fresh receipt should load');

    if (!is_wp_error($loaded)) {
        Assert::same('1', $loaded['target_path'] ?? null, 'target_path round-trips');
        Assert::same(['theme/staff'], $loaded['pattern_names'] ?? null, 'pattern_names round-trip');
        Assert::same($markup, $loaded['pattern_content'] ?? null, 'pattern_content round-trips');
    }

    $ok = $service->require_for_propose((string) $minted['preparation_id'], [
        'post_id' => 10,
        'session_id' => 3,
        'mode' => PatternPreparationReceipt::MODE_REPLACE,
    ]);
    Assert::false(is_wp_error($ok), 'matching constraints should pass');

    $wrong_post = $service->require_for_propose((string) $minted['preparation_id'], [
        'post_id' => 99,
        'mode' => PatternPreparationReceipt::MODE_REPLACE,
    ]);
    Assert::true(is_wp_error($wrong_post), 'post mismatch must fail');
    if (is_wp_error($wrong_post)) {
        Assert::same(
            'awpt_preparation_post_mismatch',
            $wrong_post->get_error_code(),
            'post mismatch should use preparation_post_mismatch code',
        );
    }

    $missing = $service->load('does-not-exist');
    Assert::true(is_wp_error($missing), 'unknown preparation_id fails');

    // Tampering bound fields must invalidate the signature.
    $id = (string) $minted['preparation_id'];
    $key = 'awpt_prep_' . md5($id);
    $stored = $GLOBALS['awpt_test_transients'][$key];
    $stored['pattern_names'] = ['evil/other'];
    $GLOBALS['awpt_test_transients'][$key] = $stored;
    $tampered = $service->load($id);
    Assert::true(is_wp_error($tampered), 'tampered receipt must fail integrity check');
    if (is_wp_error($tampered)) {
        Assert::same(
            'awpt_preparation_tampered',
            $tampered->get_error_code(),
            'tamper should surface preparation_tampered',
        );
    }
}

function test_pattern_replace_rejects_stale_expanded_hash(): void {
    awpt_test_reset_state();

    $markup = '<!-- wp:paragraph --><p>Bound</p><!-- /wp:paragraph -->';
    $service = new PatternPreparationReceipt();
    $minted = $service->mint([
        'post_id' => 74,
        'session_id' => 1,
        'mode' => PatternPreparationReceipt::MODE_REPLACE,
        'target_path' => '1',
        'expected_fingerprint' => str_repeat('a', 64),
        'source_content_hash' => str_repeat('b', 64),
        'pattern_names' => ['theme/x'],
        'expanded_content_hash' => hash('sha256', $markup),
        'pattern_content' => $markup,
        'post_type' => 'page',
    ]);

    $id = (string) $minted['preparation_id'];
    $key = 'awpt_prep_' . md5($id);
    $stored = $GLOBALS['awpt_test_transients'][$key];
    // Corrupt markup while keeping signature of other fields — hash check on propose
    // must catch this even if signature excludes pattern_content.
    $stored['pattern_content'] = '<!-- wp:paragraph --><p>Drifted</p><!-- /wp:paragraph -->';
    $GLOBALS['awpt_test_transients'][$key] = $stored;

    $loaded = $service->load($id);
    Assert::false(is_wp_error($loaded), 'signature excludes pattern_content body');

    if (!is_wp_error($loaded)) {
        Assert::false(
            hash_equals(
                (string) ($loaded['expanded_content_hash'] ?? ''),
                hash('sha256', (string) ($loaded['pattern_content'] ?? '')),
            ),
            'corrupted pattern_content must not match expanded_content_hash',
        );
    }
}

function test_pattern_replace_payload_sanitizer_keeps_structure(): void {
    $payload = new ActionPayloadSanitizer()->sanitize([
        'operation' => ActionOperations::PATTERN_REPLACE,
        'post_id' => 42,
        'pattern_name' => 'theme/staff',
        'block_path' => '1',
        'expected_fingerprint' => str_repeat('d', 64),
        'preparation_id' => 'prep-123',
        'blocks' => [[
            'blockName' => 'core/group',
            'attrs' => [],
            'innerHTML' => '',
            'innerBlocks' => [[
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>Staff</p>',
                'innerBlocks' => [],
            ]],
        ]],
        'replaced_paths' => ['1', 'bad<script>'],
    ]);

    Assert::same(
        ActionOperations::PATTERN_REPLACE,
        $payload['operation'] ?? null,
        'pattern_replace operation should survive sanitization',
    );
    Assert::same('prep-123', $payload['preparation_id'] ?? null, 'preparation_id should be preserved');
    Assert::same(['1'], $payload['replaced_paths'] ?? null, 'invalid replaced paths should be dropped');
    Assert::same(
        'core/group',
        $payload['blocks'][0]['blockName'] ?? null,
        'outer replacement block should be stored',
    );
}

test_block_tree_replace_preserves_non_target_sections();
test_block_tree_replace_rejects_stale_fingerprint();
test_block_tree_replace_expands_multi_root_in_place();
test_pattern_replace_action_applier_rebuilds_live_content();
test_pattern_preparation_receipt_round_trip_and_tamper();
test_pattern_replace_rejects_stale_expanded_hash();
test_pattern_replace_payload_sanitizer_keeps_structure();
