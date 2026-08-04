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

    $heading = BlockTree::from_content('<!-- wp:heading {"level":4} --><h4>Question</h4><!-- /wp:heading -->');
    $heading_block = $heading->normalized()[0] ?? [];
    $promoted = $heading->update_attrs('0', ['level' => 2], (string) ($heading_block['fingerprint'] ?? ''));
    Assert::false(is_wp_error($promoted), 'heading attribute update should succeed');
    if (!is_wp_error($promoted)) {
        Assert::true(
            str_contains($promoted['content'], '<h2>Question</h2>'),
            'heading attribute update must serialize the matching semantic HTML tag',
        );
    }

    $stale = $tree->update_attrs('1', ['width' => '220'], str_repeat('0', 64));
    Assert::true(is_wp_error($stale), 'stale block fingerprints should be rejected');
}

function test_block_tree_insert_and_remove(): void {
    awpt_test_reset_state();

    $content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:image {"width":"120"} --><figure>Image</figure><!-- /wp:image -->';

    $tree = BlockTree::from_content($content);
    $inserted = $tree->insert_block(
        '0',
        [
            'blockName' => 'core/spacer',
            'attrs' => ['height' => '24px'],
            'innerHTML' => '',
            'innerBlocks' => [],
            'innerContent' => [''],
        ],
        'after',
    );

    Assert::false(is_wp_error($inserted), 'insert after path 0 should succeed');

    if (!is_wp_error($inserted)) {
        Assert::same('1', $inserted['path'] ?? null, 'inserted block should land at path 1');
        Assert::true(
            str_contains($inserted['content'], '<!-- wp:spacer'),
            'serialized content should include the inserted spacer',
        );

        $after_insert = BlockTree::from_content($inserted['content']);
        Assert::same(3, $after_insert->count(), 'tree should contain three named blocks after insert');

        $spacer = $after_insert->get_block('1');
        Assert::same('core/spacer', $spacer['blockName'] ?? null, 'path 1 should be the spacer');

        $fingerprint = is_array($spacer) ? BlockTree::fingerprint($spacer) : '';
        $removed = $after_insert->remove_block('1', $fingerprint);
        Assert::false(is_wp_error($removed), 'remove by path and fingerprint should succeed');

        if (!is_wp_error($removed)) {
            Assert::false(
                str_contains($removed['content'], '<!-- wp:spacer'),
                'serialized content should drop the spacer after remove',
            );
            Assert::same(2, BlockTree::from_content($removed['content'])->count(), 'two blocks remain after remove');
        }

        $stale_remove = $after_insert->remove_block('1', str_repeat('a', 64));
        Assert::true(is_wp_error($stale_remove), 'stale fingerprint should reject remove');
    }

    $appended = BlockTree::from_content($content)->insert_block(
        '',
        [
            'blockName' => 'core/paragraph',
            'attrs' => [],
            'innerHTML' => '<p>Outro</p>',
            'innerBlocks' => [],
            'innerContent' => ['<p>Outro</p>'],
        ],
        'append',
    );
    Assert::false(is_wp_error($appended), 'append to root should succeed');

    if (!is_wp_error($appended)) {
        Assert::same('2', $appended['path'] ?? null, 'appended root block should be path 2');
    }

    $flat = BlockTree::from_content($content)->flat_list('core/image');
    Assert::same(1, count($flat), 'flat list filter should return image blocks only');
    Assert::same('1', $flat[0]['path'] ?? null, 'image block path should remain 1');
}

function test_block_tree_insert_composition_in_order(): void {
    awpt_test_reset_state();

    $tree = BlockTree::from_content('<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->');
    $composition = [
        [
            'blockName' => 'core/heading',
            'attrs' => ['level' => 2],
            'innerHTML' => '<h2>Pattern title</h2>',
            'innerBlocks' => [],
            'innerContent' => ['<h2>Pattern title</h2>'],
        ],
        [
            'blockName' => 'core/group',
            'attrs' => ['layout' => ['type' => 'constrained']],
            'innerHTML' => '',
            'innerBlocks' => [[
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>Pattern body</p>',
                'innerBlocks' => [],
                'innerContent' => ['<p>Pattern body</p>'],
            ]],
            'innerContent' => [null],
        ],
    ];
    $inserted = $tree->insert_blocks('0', $composition, 'after');

    Assert::false(is_wp_error($inserted), 'a multi-block pattern composition should stage successfully');

    if (!is_wp_error($inserted)) {
        Assert::same(['1', '2'], $inserted['paths'] ?? null, 'composition paths should preserve block order');
        Assert::true(
            str_contains($inserted['content'], '<h2>Pattern title</h2>')
            && str_contains($inserted['content'], '<p>Pattern body</p>'),
            'composition serialization should retain nested and sibling block content',
        );
        Assert::true(
            BlockTree::from_content($inserted['content'])->count() >= 4,
            'the serialized composition should retain both inserted blocks and the original neighbors',
        );
    }
}

function test_block_tree_nested_insert_updates_wordpress_inner_content_placeholders(): void {
    $paragraph = static fn(string $text): array => [
        'blockName' => 'core/paragraph',
        'attrs' => [],
        'innerBlocks' => [],
        'innerHTML' => '<p>' . $text . '</p>',
        'innerContent' => ['<p>' . $text . '</p>'],
    ];
    $inner = [
        'blockName' => 'core/group',
        'attrs' => [],
        'innerBlocks' => [$paragraph('First'), $paragraph('Second')],
        'innerHTML' => '<div><p>First</p><p>Second</p></div>',
        'innerContent' => ['<div>', null, null, '</div>'],
    ];
    $outer = [
        'blockName' => 'core/group',
        'attrs' => [],
        'innerBlocks' => [$paragraph('Intro'), $inner],
        'innerHTML' => '<section><p>Intro</p><div><p>First</p><p>Second</p></div></section>',
        'innerContent' => ['<section>', null, null, '</section>'],
    ];
    $image = [
        'blockName' => 'core/image',
        'attrs' => ['id' => 5],
        'innerBlocks' => [],
        'innerHTML' => '<figure><img class="wp-image-5"/></figure>',
        'innerContent' => ['<figure><img class="wp-image-5"/></figure>'],
    ];

    $inserted = new BlockTree([$outer])->insert_block('0.1.0', $image, BlockTree::POSITION_AFTER);

    Assert::false(is_wp_error($inserted), 'a nested insertion should serialize');

    if (!is_wp_error($inserted)) {
        $content = $inserted['content'];
        $first = strpos($content, '<p>First</p>');
        $placed = strpos($content, 'wp-image-5');
        $second = strpos($content, '<p>Second</p>');
        Assert::true(
            is_int($first) && is_int($placed) && is_int($second) && $first < $placed && $placed < $second,
            'the new child placeholder should preserve its requested nested position',
        );
    }
}

test_block_tree_paths_and_updates();
test_block_tree_insert_and_remove();
test_block_tree_insert_composition_in_order();
test_block_tree_nested_insert_updates_wordpress_inner_content_placeholders();
