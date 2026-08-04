<?php

/** Compact pattern composition contracts. @package AWPT */

declare(strict_types=1);

use AWPT\Domain\PatternCompositionBuilder;
use AWPT\Domain\PatternEditableSlots;
use AWPT\Domain\PatternMediaPlacer;
use AWPT\Domain\PatternMediaSlots;
use AWPT\Domain\PatternTextUpdater;

function test_pattern_editable_slots_and_text_updates_use_stable_paths(): void {
    $content =
        '<!-- wp:heading {"level":2,"className":"eyebrow"} --><h2 class="wp-block-heading eyebrow">Old heading</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Old paragraph</p><!-- /wp:paragraph -->';
    $slots = new PatternEditableSlots()->from_content($content);

    Assert::same('0', $slots[0]['block_path'] ?? '', 'heading should expose a stable visible path');
    Assert::same('Old heading', $slots[0]['current_text'] ?? '', 'heading text should be compact evidence');
    Assert::same('1', $slots[1]['block_path'] ?? '', 'paragraph should expose the next stable path');

    $updated = new PatternTextUpdater()->apply($content, [
        ['block_path' => '0', 'content' => 'Commander <em>News</em>'],
        ['block_path' => '1', 'content' => 'A social format built around legendary creatures.'],
    ]);

    Assert::true(is_string($updated), 'valid text slot updates should serialize');
    Assert::true(
        is_string($updated)
        && str_contains($updated, '<h2 class="wp-block-heading eyebrow">Commander <em>News</em></h2>'),
        'text updates should preserve the theme wrapper and attributes',
    );
    Assert::false(is_string($updated) && str_contains($updated, 'Old paragraph'), 'old slot content should be removed');
}

function test_pattern_text_update_rejects_non_text_blocks(): void {
    $content = '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="x"/></figure><!-- /wp:image -->';
    $updated = new PatternTextUpdater()->apply($content, [['block_path' => '0', 'content' => 'Nope']]);

    Assert::same(
        'awpt_pattern_text_block_not_editable',
        is_wp_error($updated) ? $updated->get_error_code() : '',
        'path updates must not silently rewrite non-text blocks',
    );
}

function test_pattern_text_update_accepts_idempotent_slot_values(): void {
    $content = '<!-- wp:heading --><h2>Upcoming Events</h2><!-- /wp:heading -->';
    $updated = new PatternTextUpdater()->apply($content, [[
        'block_path' => '0',
        'content' => 'Upcoming Events',
    ]]);

    Assert::same($content, $updated, 'repeating the current slot value should be a successful no-op');
}

function test_pattern_editable_slots_exclude_dynamic_query_descendants_and_unreplaceable_markup(): void {
    $content =
        '<!-- wp:heading --><h2>Static introduction</h2><!-- /wp:heading -->'
        . '<!-- wp:query --><div><!-- wp:post-template --><div>'
        . '<!-- wp:heading --><h3>Runtime post heading</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Runtime post excerpt</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:post-template --></div><!-- /wp:query -->';
    $slots = new PatternEditableSlots()->from_content($content);

    Assert::same(1, count($slots), 'query-loop descendants should not be advertised as static page copy');
    Assert::same('Static introduction', $slots[0]['current_text'] ?? '', 'the static heading should remain editable');
    Assert::false(PatternTextUpdater::is_replaceable_slot([
        'blockName' => 'core/heading',
        'innerHTML' => '',
    ]), 'a text block without a replaceable wrapper must not enter the slot contract');
}

function test_pattern_media_placer_uses_explicit_anchor_and_preserves_requested_order(): void {
    awpt_test_reset_state();

    foreach ([5, 8] as $id) {
        $attachment = new WP_Post();
        $attachment->ID = $id;
        $attachment->post_type = 'attachment';
        $attachment->post_status = 'inherit';
        $GLOBALS['awpt_test_posts'][$id] = $attachment;
        $GLOBALS['awpt_test_attachment_is_image'][$id] = true;
    }

    $GLOBALS['awpt_test_attachment_urls'][5] = 'https://example.test/uploads/teferi.png';
    $GLOBALS['awpt_test_attachment_image_urls'][5] = 'https://example.test/uploads/teferi-1200x865.png';

    $content =
        '<!-- wp:heading --><h2>Intro</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';
    $placed = new PatternMediaPlacer()->apply($content, [
        ['attachment_id' => 5, 'block_path' => '0', 'position' => 'after', 'alt' => 'Teferi'],
        ['attachment_id' => 8, 'block_path' => '0', 'position' => 'after', 'alt' => 'Commander table'],
    ]);

    Assert::true(is_string($placed), 'valid explicit media placements should serialize');
    $first = is_string($placed) ? strpos($placed, 'wp-image-5') : false;
    $second = is_string($placed) ? strpos($placed, 'wp-image-8') : false;
    Assert::true(is_int($first) && is_int($second) && $first < $second, 'equal anchors should preserve input order');
    Assert::true(is_string($placed) && str_contains($placed, '<h2>Intro</h2>'), 'anchor content should be preserved');
    Assert::true(
        is_string($placed) && str_contains($placed, 'https://example.test/uploads/teferi.png'),
        'media placement should persist the canonical attachment URL',
    );
    Assert::false(
        is_string($placed) && str_contains($placed, 'teferi-1200x865.png'),
        'generated intermediate-size URLs should not become attachment identity',
    );
}

function test_pattern_media_slots_and_featured_cover_placement_preserve_hero_structure(): void {
    awpt_test_reset_state();
    $attachment = new WP_Post();
    $attachment->ID = 47;
    $attachment->post_type = 'attachment';
    $attachment->post_status = 'inherit';
    $GLOBALS['awpt_test_posts'][47] = $attachment;
    $GLOBALS['awpt_test_attachment_is_image'][47] = true;
    $GLOBALS['awpt_test_attachment_urls'][47] = 'https://example.test/uploads/maternity.png';
    $content =
        '<!-- wp:cover {"dimRatio":10,"isDark":false,"tagName":"section","className":"civicpress-hero"} -->'
        . '<section class="wp-block-cover is-light civicpress-hero">'
        . '<span class="wp-block-cover__background"></span><div class="wp-block-cover__inner-container"></div>'
        . '</section><!-- /wp:cover -->';
    $slots = new PatternMediaSlots()->from_content($content);

    Assert::same('0', $slots[0]['block_path'] ?? '', 'the hero Cover should expose its exact path');
    Assert::same(
        'featured_cover',
        $slots[0]['recommended_placement'] ?? '',
        'an empty Cover should advertise its semantic background placement',
    );

    $placed = new PatternMediaPlacer()->apply($content, [[
        'attachment_id' => 47,
        'block_path' => '0',
        'placement' => 'featured_cover',
    ]]);

    Assert::true(is_string($placed), 'a valid featured Cover assignment should serialize');
    Assert::true(
        is_string($placed) && str_contains($placed, '"useFeaturedImage":true'),
        'the hero should use the post featured image as its background',
    );
    Assert::false(
        is_string($placed) && str_contains($placed, '<!-- wp:image'),
        'a featured Cover assignment must not invent an inline Image block',
    );
    Assert::false(
        is_string($placed) && str_contains($placed, 'is-light'),
        'automatic Cover contrast should not retain a stale is-light class',
    );
}

function test_pattern_media_placer_allows_deliberate_inline_image_in_locked_group(): void {
    awpt_test_reset_state();
    $attachment = new WP_Post();
    $attachment->ID = 48;
    $attachment->post_type = 'attachment';
    $attachment->post_status = 'inherit';
    $GLOBALS['awpt_test_posts'][48] = $attachment;
    $GLOBALS['awpt_test_attachment_is_image'][48] = true;
    $content =
        '<!-- wp:group {"lock":{"move":true,"remove":true}} --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Callout copy</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $placed = new PatternMediaPlacer()->apply($content, [[
        'attachment_id' => 48,
        'block_path' => '0.0',
        'position' => 'after',
        'placement' => 'insert',
        'alt' => 'Supporting callout image',
    ]]);

    Assert::true(is_string($placed), 'move/remove locks must not prohibit an intentional child image');
}

function test_pattern_composition_builder_materializes_any_ordered_pattern_list(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'demo/layout',
            'title' => 'Layout',
            'content' => '<!-- wp:heading --><h2>Intro</h2><!-- /wp:heading -->',
        ],
        [
            'name' => 'demo/comparison',
            'title' => 'Comparison',
            'content' => '<!-- wp:paragraph --><p>Compare these options.</p><!-- /wp:paragraph -->',
        ],
        [
            'name' => 'demo/details',
            'title' => 'Details',
            'content' => '<!-- wp:paragraph --><p>Read the details.</p><!-- /wp:paragraph -->',
        ],
    ];

    $built = new PatternCompositionBuilder()->build(
        ['demo/layout', 'demo/comparison', 'demo/details'],
        [],
        [
            ['block_path' => '0', 'content' => 'Commander News'],
            ['block_path' => '1', 'content' => 'Commander versus Modern'],
            ['block_path' => '2', 'content' => 'How Commander works'],
        ],
    );

    Assert::true(is_string($built), 'an ordered multi-pattern composition should materialize');
    Assert::true(
        is_string($built)
        && strpos($built, 'Commander News') < strpos($built, 'Commander versus Modern')
        && strpos($built, 'Commander versus Modern') < strpos($built, 'How Commander works'),
        'all requested patterns and their stable combined paths should survive in order',
    );
}

test_pattern_editable_slots_and_text_updates_use_stable_paths();
test_pattern_text_update_rejects_non_text_blocks();
test_pattern_text_update_accepts_idempotent_slot_values();
test_pattern_editable_slots_exclude_dynamic_query_descendants_and_unreplaceable_markup();
test_pattern_media_placer_uses_explicit_anchor_and_preserves_requested_order();
test_pattern_media_slots_and_featured_cover_placement_preserve_hero_structure();
test_pattern_media_placer_allows_deliberate_inline_image_in_locked_group();
test_pattern_composition_builder_materializes_any_ordered_pattern_list();
