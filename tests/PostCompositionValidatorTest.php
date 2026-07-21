<?php

/**
 * Tests generated Gutenberg composition validation.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PostCompositionValidator;

function test_post_composition_validator_accepts_required_media_and_link(): void {
    $content =
        '<!-- wp:cover {"id":66,"url":"https://example.test/uploads/image-66.jpg"} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-66" '
        . 'src="https://example.test/uploads/image-66.jpg"/><a href="https://maternity-war.com/">Shop now</a></div>'
        . '<!-- /wp:cover -->';
    $error = new PostCompositionValidator()->validate($content, [66], ['https://maternity-war.com'], 'civicpress/', [
        'pattern_name' => 'civicpress/header-hero',
    ]);

    Assert::same(null, $error, 'valid Cover media, CTA, and pattern provenance should pass');
}

function test_post_composition_validator_rejects_featured_only_media(): void {
    $content = '<!-- wp:paragraph --><p>Comfortable cotton clothing.</p><!-- /wp:paragraph -->';
    $error = new PostCompositionValidator()->validate($content, [66]);

    Assert::true(is_wp_error($error), 'required media should have to appear inline');
    Assert::same('awpt_required_inline_media_missing', $error?->get_error_code(), 'missing-media error code');
}

function test_post_composition_validator_rejects_malformed_blocks(): void {
    $unbalanced = '<!-- wp:cover {"id":66} --><div>Hero</div><!-- /wp:group -->';
    $nested = '<!-- wp:paragraph --><p><h1>Broken</h1></p><!-- /wp:paragraph -->';
    $unbalanced_error = new PostCompositionValidator()->validate($unbalanced);
    $nested_error = new PostCompositionValidator()->validate($nested);

    Assert::same(
        'awpt_unbalanced_block_markup',
        $unbalanced_error?->get_error_code(),
        'mismatched block delimiters should be rejected',
    );
    Assert::same(
        'cover',
        $unbalanced_error?->get_error_data()['expected_closing_block'] ?? null,
        'mismatch diagnostics should identify the block that needs closing',
    );
    Assert::same(
        'group',
        $unbalanced_error?->get_error_data()['actual_closing_block'] ?? null,
        'mismatch diagnostics should identify the incorrect closing block',
    );
    Assert::same(
        'awpt_invalid_block_html',
        $nested_error?->get_error_code(),
        'block-level HTML inside a paragraph should be rejected',
    );
}

function test_post_composition_validator_accepts_block_after_closed_paragraph(): void {
    $content =
        '<!-- wp:paragraph --><p>Soft cotton for every stage.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group --><div class="wp-block-group"><p>More details</p></div><!-- /wp:group -->';

    Assert::same(
        null,
        new PostCompositionValidator()->validate($content),
        'a block after a closed paragraph must not be mistaken for nested block-level HTML',
    );
}

function test_post_composition_validator_enforces_distinct_library_image_count(): void {
    awpt_test_reset_state();

    foreach ([77, 78, 79] as $id) {
        $attachment = new WP_Post();
        $attachment->ID = $id;
        $attachment->post_type = 'attachment';
        $GLOBALS['awpt_test_posts'][$id] = $attachment;
        $GLOBALS['awpt_test_attachment_is_image'][$id] = true;
    }

    $two_images =
        '<!-- wp:image {"id":77} --><figure><img class="wp-image-77" /></figure><!-- /wp:image -->'
        . '<!-- wp:image {"id":78} --><figure><img class="wp-image-78" /></figure><!-- /wp:image -->';
    $error = new PostCompositionValidator()->validate($two_images, [77], [], '', [
        'minimum_library_images' => 3,
    ]);
    Assert::same(
        'awpt_required_media_count_missing',
        $error?->get_error_code(),
        'fewer than the requested number of distinct Media Library images should fail',
    );
    Assert::same(null, new PostCompositionValidator()->validate($two_images, [77], [], '', [
        'minimum_library_images' => 3,
        'featured_image_id' => 79,
    ]), 'a distinct featured image should count toward an explicit Media Library image request');

    $three_images =
        $two_images
        . '<!-- wp:cover {"id":79} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background wp-image-79" /></div><!-- /wp:cover -->';
    Assert::same(null, new PostCompositionValidator()->validate($three_images, [77], [], '', [
        'minimum_library_images' => 3,
    ]), 'distinct valid image and cover attachment IDs should satisfy the media count');
}

function test_post_composition_validator_accepts_flexible_visual_placements(): void {
    awpt_test_reset_state();
    $attachment = new WP_Post();
    $attachment->ID = 80;
    $attachment->post_type = 'attachment';
    $GLOBALS['awpt_test_posts'][80] = $attachment;
    $GLOBALS['awpt_test_attachment_is_image'][80] = true;
    $content =
        '<!-- wp:cover {"url":"https://example.test/hero.jpg"} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
        . '<!-- wp:outermost/icon-block --><div class="wp-block-outermost-icon-block"></div>'
        . '<!-- /wp:outermost/icon-block -->';

    Assert::same(null, new PostCompositionValidator()->validate($content, [], [], '', [
        'minimum_visuals' => 3,
        'featured_image_id' => 80,
    ]), 'a cover, icon, and featured image should satisfy a general three-image request');
}

function test_post_composition_validator_reports_independent_issues_together(): void {
    $issues = new PostCompositionValidator()->diagnose(
        '<!-- wp:paragraph --><p>No visual or link yet.</p><!-- /wp:paragraph -->',
        [],
        ['https://maternity-wars.com'],
        'civicpress/',
        ['pattern_name' => 'invented/pattern', 'minimum_visuals' => 3],
    );
    $codes = array_column($issues, 'code');

    Assert::true(in_array('awpt_required_pattern_missing', $codes, true), 'pattern issue should be reported');
    Assert::true(in_array('awpt_required_visual_count_missing', $codes, true), 'visual issue should be reported');
    Assert::true(in_array('awpt_required_link_missing', $codes, true), 'link issue should be reported');
}

function test_post_composition_validator_rejects_editor_invalid_static_markup(): void {
    $wrapper = new PostCompositionValidator()->validate(
        '<!-- wp:group --><section class="wp-block-group"></section><!-- /wp:group -->',
    );
    $cover = new PostCompositionValidator()->validate(
        '<!-- wp:cover {"id":88,"url":"https://example.test/image.jpg"} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" /></div>'
        . '<!-- /wp:cover -->',
    );
    $media_text = new PostCompositionValidator()->validate(
        '<!-- wp:media-text {"mediaId":66,"mediaType":"image"} -->'
        . '<div class="wp-block-media-text"><figure><img class="wp-image-66" /></figure></div>'
        . '<!-- /wp:media-text -->',
    );

    Assert::same('awpt_block_wrapper_mismatch', $wrapper?->get_error_code(), 'group tag mismatches should fail');
    Assert::same('awpt_invalid_static_block_markup', $cover?->get_error_code(), 'cover image classes should validate');
    Assert::same(
        'awpt_invalid_static_block_markup',
        $media_text?->get_error_code(),
        'media-text image classes should validate',
    );
}

function test_post_composition_validator_rejects_visible_pattern_placeholders(): void {
    $content =
        '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link wp-element-button">Optional Call To Action</a>'
        . '</div><!-- /wp:button -->';
    $error = new PostCompositionValidator()->validate($content);

    Assert::same(
        'awpt_placeholder_content_remaining',
        $error?->get_error_code(),
        'generic pattern button copy should not reach preview',
    );
}

test_post_composition_validator_accepts_required_media_and_link();
test_post_composition_validator_rejects_featured_only_media();
test_post_composition_validator_rejects_malformed_blocks();
test_post_composition_validator_accepts_block_after_closed_paragraph();
test_post_composition_validator_enforces_distinct_library_image_count();
test_post_composition_validator_accepts_flexible_visual_placements();
test_post_composition_validator_reports_independent_issues_together();
test_post_composition_validator_rejects_editor_invalid_static_markup();
test_post_composition_validator_rejects_visible_pattern_placeholders();
