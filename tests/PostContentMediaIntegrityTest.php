<?php

/**
 * Tests deterministic Media Library repairs across multiple Gutenberg blocks.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PostContentMediaIntegrity;

function test_post_content_media_integrity_repairs_each_verified_attachment_independently(): void {
    awpt_test_reset_state();

    foreach ([5, 6, 7] as $id) {
        $attachment = new WP_Post();
        $attachment->ID = $id;
        $attachment->post_type = 'attachment';
        $GLOBALS['awpt_test_posts'][$id] = $attachment;
        $GLOBALS['awpt_test_attachment_is_image'][$id] = true;
        $GLOBALS['awpt_test_attachment_urls'][$id] = "https://example.test/wp-content/uploads/2026/07/image-{$id}.png";
    }

    $content =
        '<!-- wp:cover {"dimRatio":40} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background wp-image-5" src="https://example.test/wp-content/uploads/2026/07/image.jpg" />'
        . '</div><!-- /wp:cover -->'
        . '<!-- wp:image --><figure class="wp-block-image">'
        . '<img class="wp-image-6" src="https://example.test/wp-content/uploads/2026/07/invented.gif" />'
        . '</figure><!-- /wp:image -->'
        . '<!-- wp:media-text {"mediaType":"image"} --><div class="wp-block-media-text"><figure>'
        . '<img class="wp-image-7 size-full" src="https://example.test/wp-content/uploads/2026/07/incorrect.jpg" />'
        . '</figure></div><!-- /wp:media-text -->';
    $first = new PostContentMediaIntegrity()->prepare($content);

    Assert::true(is_array($first), 'verified attachment classes should permit deterministic repair');

    foreach ([5, 6, 7] as $id) {
        Assert::true(
            str_contains($first['content'], "https://example.test/wp-content/uploads/2026/07/image-{$id}.png"),
            "attachment #{$id} should use its canonical URL",
        );
    }

    Assert::false(str_contains($first['content'], 'image.jpg'), 'the fabricated Cover URL should be removed');
    Assert::true(str_contains($first['content'], '"id":5'), 'class-only Covers should gain a Gutenberg attachment ID');
    Assert::true(str_contains($first['content'], '"id":6'), 'class-only Images should gain a Gutenberg attachment ID');
    Assert::true(
        str_contains($first['content'], '"mediaId":7'),
        'class-only Media & Text blocks should gain a Media Library ID',
    );
    Assert::same(3, count($first['repairs']), 'every independently identified image should report its own repair');

    $second = new PostContentMediaIntegrity()->prepare($first['content']);
    Assert::true(is_array($second), 'canonicalized content should remain valid');
    Assert::same([], $second['repairs'], 'a second integrity pass should be idempotent');
}

function test_post_content_media_integrity_blocks_ambiguous_local_upload_urls_without_guessing(): void {
    awpt_test_reset_state();

    $ambiguous = new PostContentMediaIntegrity()->prepare(
        '<!-- wp:cover {"url":"https://example.test/wp-content/uploads/2026/07/unknown.jpg"} -->'
        . '<div class="wp-block-cover"><img src="https://example.test/wp-content/uploads/2026/07/unknown.jpg" /></div>'
        . '<!-- /wp:cover -->',
    );

    Assert::same(
        'awpt_unresolved_local_media_url',
        $ambiguous instanceof WP_Error ? $ambiguous->get_error_code() : '',
        'same-site uploads without a verified attachment identity should block staging',
    );

    $external = new PostContentMediaIntegrity()->prepare(
        '<!-- wp:cover {"url":"https://images.example.org/hero.jpg"} -->'
        . '<div class="wp-block-cover"><img src="https://images.example.org/hero.jpg" /></div><!-- /wp:cover -->',
    );

    Assert::true(is_array($external), 'external URLs without an attachment identity should stay untouched');
}

test_post_content_media_integrity_repairs_each_verified_attachment_independently();
test_post_content_media_integrity_blocks_ambiguous_local_upload_urls_without_guessing();
