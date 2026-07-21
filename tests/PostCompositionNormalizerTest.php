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

test_post_composition_normalizer_aligns_group_wrapper_metadata();
test_post_composition_normalizer_repairs_cover_and_media_text_classes();
test_post_composition_normalizer_is_idempotent_and_does_not_rewrite_copy();
