<?php

/**
 * Tests for AWPT\Support\BlockTree.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\BlockTree;

function test_block_tree_paths_and_updates(): void {
    awpt_test_reset_state();

    $content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:image {"width":"120","id":9} --><figure>Image</figure><!-- /wp:image -->';

    $tree = BlockTree::from_content($content);
    $blocks = $tree->normalized();

    Assert::same(2, $tree->count(), 'BlockTree should count named top-level blocks');
    Assert::same('0', $blocks[0]['path'] ?? null, 'first block should have path 0');
    Assert::same('1', $blocks[1]['path'] ?? null, 'second block should have path 1');
    Assert::same('core/image', $blocks[1]['name'] ?? null, 'core block names should be normalized');
    Assert::true(
        is_string($blocks[1]['fingerprint'] ?? null) && strlen((string) $blocks[1]['fingerprint']) === 64,
        'normalized blocks should include a stable fingerprint',
    );

    $updated = $tree->update_attrs('1', ['width' => '180'], (string) $blocks[1]['fingerprint']);
    Assert::false(is_wp_error($updated), 'updating attrs by valid path and fingerprint should succeed');

    if (!is_wp_error($updated)) {
        Assert::true(
            str_contains($updated['content'], '<!-- wp:image {"width":"180","id":9} -->'),
            'serialized content should update only the target attrs',
        );
    }

    $stale = $tree->update_attrs('1', ['width' => '220'], str_repeat('0', 64));
    Assert::true(is_wp_error($stale), 'stale block fingerprints should be rejected');
}

test_block_tree_paths_and_updates();
