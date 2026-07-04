<?php

/**
 * Tests for AWPT\Support\StagedPostPreview.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\StagedPostPreview;

function test_staged_post_preview(): void
{
    $preview = new StagedPostPreview();

    awpt_test_reset_state();
    $GLOBALS['awpt_test_next_post_id'] = 501;
    $prepared = $preview->prepare_new_post_payload([
        'operation' => 'new_post',
        'post_title' => 'Deadpool vs Lady Deadpool',
        'post_content' => '<p>Ten paragraphs of comparison.</p>',
        'post_type' => 'post',
        'featured_image_id' => 0,
    ]);
    Assert::false(is_wp_error($prepared), 'new post payloads should create a staging draft');
    Assert::same(501, $prepared['post_id'] ?? null, 'staging draft ID should be stored on the payload');
    Assert::true((bool) ($prepared['staging_draft'] ?? false), 'payload should be marked as a staging draft');
    Assert::true(
        str_contains((string) ($prepared['preview_url'] ?? ''), '501'),
        'preview URL should reference the staging draft',
    );
    Assert::same(
        1,
        $GLOBALS['awpt_test_post_meta_updates'][501]['_awpt_staging_draft'] ?? null,
        'staging meta should be written on the draft',
    );

    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 77;
    $post->post_title = 'Existing staging draft';
    $post->post_status = 'draft';
    $GLOBALS['awpt_test_posts'][77] = $post;
    $GLOBALS['awpt_test_post_meta_updates'][77]['_awpt_staging_draft'] = 1;
    $result = $preview->preview_from_payload([
        'operation' => 'new_post',
        'post_id' => 77,
        'staging_draft' => true,
        'post_title' => 'Updated title',
        'post_content' => '<p>Updated body.</p>',
    ]);
    Assert::false(is_wp_error($result), 'existing staging drafts should preview');
    Assert::same('Updated title', $result['title'] ?? null, 'preview title should come from payload');

    awpt_test_reset_state();
    $GLOBALS['awpt_test_post_meta_updates'][88]['_awpt_staging_draft'] = 1;
    $preview->discard_staging_draft([
        'operation' => 'new_post',
        'post_id' => 88,
    ]);
    Assert::same([88], $GLOBALS['awpt_test_trashed_posts'] ?? [], 'rejecting should trash the staging draft');
}

test_staged_post_preview();
