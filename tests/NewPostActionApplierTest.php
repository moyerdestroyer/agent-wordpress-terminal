<?php

/**
 * Tests for AWPT\Abilities\ActionAppliers\NewPostActionApplier.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\ActionAppliers\NewPostActionApplier;

function test_new_post_action_applier(): void {
    $applier = new NewPostActionApplier();

    // A well-formed payload creates a draft post and returns its ID and edit link.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_next_post_id'] = 99;
    $result = $applier->apply([
        'post_title' => 'Deadpool and Lady Deadpool',
        'post_content' => 'A thoughtful look at two very different Wades.',
        'post_type' => 'post',
    ]);
    Assert::false(is_wp_error($result), 'a valid new-post payload should succeed');

    if (!is_wp_error($result)) {
        Assert::same(99, $result['post_id'], 'the created post ID should be returned');
        Assert::true(
            str_contains((string) $result['edit_url'], '99'),
            'the returned edit URL should reference the new post ID',
        );
    }

    // featured_image_id is applied via set_post_thumbnail after insert.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_next_post_id'] = 100;
    $attachment = new WP_Post();
    $attachment->post_type = 'attachment';
    $GLOBALS['awpt_test_posts'][55] = $attachment;
    $GLOBALS['awpt_test_attachment_is_image'][55] = true;
    $result = $applier->apply([
        'post_title' => 'With featured image',
        'post_content' => 'Body text.',
        'featured_image_id' => 55,
    ]);
    Assert::false(is_wp_error($result), 'a payload with featured_image_id should succeed');

    if (!is_wp_error($result)) {
        Assert::same(55, $GLOBALS['awpt_test_post_thumbnails'][100] ?? null, 'featured image should be set');
        Assert::same(55, $result['featured_image_id'] ?? null, 'featured_image_id should be echoed in the result');
    }

    // A staging draft is finalized in place instead of inserting a duplicate post.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_post_meta_updates'][88]['_awpt_staging_draft'] = 1;
    $result = $applier->apply([
        'post_id' => 88,
        'staging_draft' => true,
        'post_title' => 'Final draft title',
        'post_content' => 'Final draft body.',
        'featured_image_id' => 55,
    ]);
    Assert::false(is_wp_error($result), 'a staging draft payload should finalize the existing draft');

    if (!is_wp_error($result)) {
        Assert::same(88, $result['post_id'], 'the staging draft ID should be returned');
        Assert::false(
            array_key_exists('_awpt_staging_draft', $GLOBALS['awpt_test_post_meta_updates'][88] ?? []),
            'staging meta should be removed after apply',
        );
    }

    // Featured image already set on the staging draft should not fail apply.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_post_meta_updates'][89]['_awpt_staging_draft'] = 1;
    $GLOBALS['awpt_test_post_thumbnails'][89] = 55;
    $result = $applier->apply([
        'post_id' => 89,
        'staging_draft' => true,
        'post_title' => 'Already has thumbnail',
        'post_content' => 'Body text.',
        'featured_image_id' => 55,
    ]);
    Assert::false(
        is_wp_error($result),
        'apply should succeed when the featured image is already assigned on the staging draft',
    );

    // Failure to set the featured image surfaces as an error even when the post was created.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_next_post_id'] = 101;
    $GLOBALS['awpt_test_set_post_thumbnail_result'] = false;
    $result = $applier->apply([
        'post_title' => 'Thumbnail failure',
        'post_content' => 'Body text.',
        'featured_image_id' => 55,
    ]);
    Assert::true(is_wp_error($result), 'set_post_thumbnail failure should be reported');

    if (is_wp_error($result)) {
        Assert::same('awpt_featured_image_failed', $result->get_error_code(), 'featured-image error code');
    }

    // A missing title is rejected before ever calling wp_insert_post.
    awpt_test_reset_state();
    $result = $applier->apply(['post_title' => '', 'post_content' => 'Some content']);
    Assert::true(is_wp_error($result), 'a missing post title should be rejected');

    if (is_wp_error($result)) {
        Assert::same('awpt_empty_action', $result->get_error_code(), 'missing-title error code');
    }

    // A missing content body is rejected too.
    awpt_test_reset_state();
    $result = $applier->apply(['post_title' => 'A title', 'post_content' => '']);
    Assert::true(is_wp_error($result), 'a missing post content should be rejected');

    // Users without edit_posts capability are denied, regardless of payload content.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = static fn(string $capability): bool => 'edit_posts' !== $capability;
    $result = $applier->apply(['post_title' => 'Title', 'post_content' => 'Content']);
    Assert::true(is_wp_error($result), 'a user without edit_posts should be denied');

    if (is_wp_error($result)) {
        Assert::same('awpt_cannot_create_post', $result->get_error_code(), 'permission-denied error code');
    }

    // An unsupported post type is silently normalized to "post" rather than rejected,
    // since the proposing ability already validates this before staging.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_next_post_id'] = 7;
    $result = $applier->apply([
        'post_title' => 'Title',
        'post_content' => 'Content',
        'post_type' => 'attachment',
    ]);
    Assert::false(is_wp_error($result), 'an unrecognized post type should not block creation');
}

test_new_post_action_applier();
