<?php

/**
 * Tests safe Gutenberg composition repairs.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PostCompositionNormalizer;
use AWPT\Support\PostCompositionValidator;

function test_post_composition_normalizer_aligns_group_wrapper_metadata(): void {
    $result = new PostCompositionNormalizer()->normalize(
        '<!-- wp:group --><section class="wp-block-group"><p>Original copy</p></section><!-- /wp:group -->',
    );

    Assert::true(
        str_contains($result['content'], '<!-- wp:group {"tagName":"section"} -->'),
        'a non-default saved Group wrapper should be recorded in block attributes',
    );
    Assert::true(str_contains($result['content'], 'Original copy'), 'repairs must preserve agent-authored copy');
    Assert::same('wrapper_tag_alignment', $result['repairs'][0]['kind'] ?? '', 'wrapper repair should be reported');
    Assert::same(
        null,
        new PostCompositionValidator()->validate($result['content']),
        'the repaired Group should validate',
    );
}

function test_post_composition_normalizer_repairs_cover_and_media_text_classes(): void {
    $cover = new PostCompositionNormalizer()->normalize(
        '<!-- wp:cover {"id":88,"tagName":"section"} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" /></div>'
        . '<!-- /wp:cover -->',
    );
    $media_text = new PostCompositionNormalizer()->normalize(
        '<!-- wp:media-text {"mediaId":66,"mediaType":"image"} -->'
        . '<div class="wp-block-media-text"><figure><img class="wp-image-66" /></figure></div>'
        . '<!-- /wp:media-text -->',
    );

    Assert::true(str_contains($cover['content'], '<section'), 'an explicit Cover tagName should win');
    Assert::true(str_contains($cover['content'], 'wp-image-88'), 'Cover attachment class should be added');
    Assert::same(2, count($cover['repairs']), 'both Cover repairs should be listed');
    Assert::same(
        null,
        new PostCompositionValidator()->validate($cover['content']),
        'the repaired Cover should validate',
    );
    Assert::true(str_contains($media_text['content'], 'size-full'), 'Media & Text size class should be added');
    Assert::same(
        null,
        new PostCompositionValidator()->validate($media_text['content']),
        'the repaired Media & Text block should validate',
    );
}

function test_post_composition_normalizer_uses_canonical_attachment_urls(): void {
    awpt_test_reset_state();

    foreach ([113, 126, 128] as $id) {
        $attachment = new WP_Post();
        $attachment->ID = $id;
        $attachment->post_type = 'attachment';
        $GLOBALS['awpt_test_posts'][$id] = $attachment;
        $GLOBALS['awpt_test_attachment_is_image'][$id] = true;
        $GLOBALS['awpt_test_attachment_urls'][$id] = "https://example.test/uploads/real-{$id}.png";
        $GLOBALS['awpt_test_attachment_image_urls'][$id] = "https://example.test/uploads/real-{$id}-1200x800.png";
    }

    $source =
        '<!-- wp:cover {"id":128,"url":"https://example.test/uploads/image-128.png"} -->'
        . '<div class="wp-block-cover"><img src="https://example.test/uploads/image-128.png" '
        . 'srcset="https://example.test/uploads/image-128-2x.png 2x" class="wp-image-128" /></div>'
        . '<!-- /wp:cover -->'
        . '<!-- wp:image {"id":126,"sizeSlug":"large"} --><figure class="wp-block-image">'
        . '<img src="https://example.test/uploads/image-126.png" class="wp-image-126" /></figure>'
        . '<!-- /wp:image -->'
        . '<!-- wp:media-text {"mediaId":113,"mediaType":"image"} --><div class="wp-block-media-text">'
        . '<figure><img src="https://example.test/uploads/image-113.png" class="wp-image-113 size-full" /></figure>'
        . '</div><!-- /wp:media-text -->';
    $result = new PostCompositionNormalizer()->normalize($source);

    foreach ([113, 126, 128] as $id) {
        Assert::true(
            str_contains($result['content'], "https://example.test/uploads/real-{$id}.png"),
            "attachment #{$id} should use its canonical Media Library URL",
        );
        Assert::false(
            str_contains($result['content'], "https://example.test/uploads/image-{$id}.png"),
            "the invented URL for attachment #{$id} should be removed",
        );
        Assert::false(
            str_contains($result['content'], "https://example.test/uploads/real-{$id}-1200x800.png"),
            "an intermediate-size URL for attachment #{$id} should not become canonical identity",
        );
    }

    Assert::false(str_contains($result['content'], 'srcset='), 'stale responsive candidates should be removed');
    Assert::same(
        3,
        count(array_filter(
            $result['repairs'],
            static fn(array $repair): bool => 'canonical_attachment_url' === ($repair['kind'] ?? ''),
        )),
        'each repaired image-bearing block should be reported',
    );

    $second = new PostCompositionNormalizer()->normalize($result['content']);
    Assert::same([], $second['repairs'], 'canonical attachment URL repair should be idempotent');
}

function test_post_composition_normalizer_is_idempotent_and_does_not_rewrite_copy(): void {
    $normalizer = new PostCompositionNormalizer();
    $first = $normalizer->normalize(
        '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link">Optional Call To Action</a></div><!-- /wp:button -->',
    );
    $second = $normalizer->normalize($first['content']);

    Assert::same([], $first['repairs'], 'semantic placeholder copy should not be deterministically rewritten');
    Assert::same([], $second['repairs'], 'normalizing already-stable content should be a no-op');
    Assert::same($first['content'], $second['content'], 'normalization should be idempotent');
    Assert::same(
        'awpt_placeholder_content_remaining',
        new PostCompositionValidator()
            ->validate($first['content'])
            ?->get_error_code(),
        'placeholder copy should still be returned to the agent for judgment',
    );
}

function test_post_composition_normalizer_wraps_bare_list_items(): void {
    $source =
        '<!-- wp:list -->'
        . '<ul>'
        . '<li>If you are under a winter storm warning, <a href="">find shelter</a> right away.</li>'
        . '<li>Sign up for <a href="">your community’s warning system</a>.</li>'
        . '</ul>'
        . '<!-- /wp:list -->';
    $result = new PostCompositionNormalizer()->normalize($source);

    Assert::same(
        'list_item_delimiters',
        $result['repairs'][0]['kind'] ?? '',
        'bare list items should be wrapped in core/list-item delimiters',
    );
    Assert::true(
        str_contains($result['content'], '<!-- wp:list-item -->'),
        'serialized content should include list-item block comments',
    );
    Assert::true(str_contains($result['content'], 'find shelter'), 'list copy must be preserved through the repair');
    Assert::same(
        null,
        new PostCompositionValidator()->validate($result['content']),
        'the repaired list should validate for the editor',
    );

    $second = new PostCompositionNormalizer()->normalize($result['content']);
    Assert::same([], $second['repairs'], 'list-item repair should be idempotent');
}

function test_post_composition_normalizer_closes_unambiguous_attribute_json(): void {
    $source =
        '<!-- wp:cover {"id":88,"style":{"spacing":{"padding":{"top":"var:preset|spacing|50"}}} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-88" /></div>'
        . '<!-- /wp:cover -->';
    $result = new PostCompositionNormalizer()->normalize($source);

    Assert::same(
        'block_attribute_json',
        $result['repairs'][0]['kind'] ?? '',
        'a missing final JSON object delimiter should be repaired and reported',
    );
    Assert::true(str_contains($result['content'], '"id":88'), 'valid attributes must survive the repair');
    Assert::same(
        null,
        new PostCompositionValidator()->validate($result['content']),
        'the repaired block attributes should validate for the editor',
    );

    $second = new PostCompositionNormalizer()->normalize($result['content']);
    Assert::same([], $second['repairs'], 'attribute JSON repair should be idempotent');
}

function test_post_composition_normalizer_repairs_bare_button_labels(): void {
    $source =
        '<!-- wp:button {"backgroundColor":"secondary","className":"is-style-fill"} -->'
        . '<div class="wp-block-button is-style-fill">Shop the Collection</div>'
        . '<!-- /wp:button -->';
    $result = new PostCompositionNormalizer()->normalize($source);

    Assert::same(
        'button_link_markup',
        $result['repairs'][0]['kind'] ?? '',
        'a bare button label should receive canonical link markup',
    );
    Assert::true(
        str_contains(
            $result['content'],
            '<a class="wp-block-button__link has-secondary-background-color has-background wp-element-button">Shop the Collection</a>',
        ),
        'the repair should preserve the label and derive the preset background classes',
    );
    Assert::same(
        null,
        new PostCompositionValidator()->validate($result['content']),
        'the repaired button should pass static markup validation',
    );

    $second = new PostCompositionNormalizer()->normalize($result['content']);
    Assert::same([], $second['repairs'], 'button link repair should be idempotent');
}

test_post_composition_normalizer_aligns_group_wrapper_metadata();
test_post_composition_normalizer_repairs_cover_and_media_text_classes();
test_post_composition_normalizer_uses_canonical_attachment_urls();
test_post_composition_normalizer_is_idempotent_and_does_not_rewrite_copy();
test_post_composition_normalizer_wraps_bare_list_items();
test_post_composition_normalizer_closes_unambiguous_attribute_json();
test_post_composition_normalizer_repairs_bare_button_labels();
