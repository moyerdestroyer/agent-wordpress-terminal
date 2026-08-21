<?php

/**
 * Tests normalize-before-validate staging pipeline.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PostCompositionValidator;
use AWPT\Support\PostContentStagingPipeline;

function test_post_content_staging_pipeline_repairs_cover_wrapper_before_validation(): void {
    $drifted =
        '<!-- wp:cover {"dimRatio":10,"isDark":false,"tagName":"section","className":"civicpress-hero"} -->'
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"level":1} --><h1>Page title</h1><!-- /wp:heading -->'
        . '</div></div><!-- /wp:cover -->';

    Assert::true(
        new PostCompositionValidator()->validate($drifted) instanceof WP_Error,
        'raw tagName drift should fail composition validation',
    );

    $pipeline = new PostContentStagingPipeline();
    $normalized = $pipeline->normalize($drifted);

    Assert::true(
        str_contains($normalized['content'], '<section'),
        'pipeline should align the Cover wrapper to the declared tagName',
    );
    Assert::true([] !== $normalized['repairs'], 'wrapper repair should be reported');
    Assert::same(
        null,
        new PostCompositionValidator()->validate($normalized['content']),
        'normalized Cover should pass composition validation',
    );

    $prepared = $pipeline->prepare($normalized['content']);
    Assert::true(is_array($prepared), 'normalized content without local media should prepare cleanly');
}

test_post_content_staging_pipeline_repairs_cover_wrapper_before_validation();

function test_post_content_staging_pipeline_still_rejects_unresolved_local_media(): void {
    awpt_test_reset_state();

    $content =
        '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="https://example.test/wp-content/uploads/2026/08/unknown-150x150.jpg" />'
        . '</figure><!-- /wp:image -->';

    $result = new PostContentStagingPipeline()->prepare($content);

    Assert::true($result instanceof WP_Error, 'unresolved local uploads must still block staging');
    Assert::same(
        'awpt_unresolved_local_media_url',
        $result instanceof WP_Error ? $result->get_error_code() : '',
        'media integrity code should be stable',
    );

    $data = $result instanceof WP_Error ? $result->get_error_data() : [];
    $data = is_array($data) ? $data : [];
    Assert::true(
        str_contains((string) ($data['recovery'] ?? ''), 'media_unavailable')
        || str_contains((string) ($data['recovery'] ?? ''), 'omit'),
        'recovery should steer omit or media_unavailable',
    );
}

test_post_content_staging_pipeline_still_rejects_unresolved_local_media();
