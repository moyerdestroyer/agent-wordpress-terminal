<?php

/**
 * Regression test for AWPT\Abilities\ActionAppliers\ContentUpdateActionApplier.
 *
 * Guards against a real bug: `current_user_can(capability: 'edit_post', args: $post_id)`
 * used named arguments for both parameters, but `current_user_can()`'s second parameter
 * is a variadic `...$args`. Naming it `args:` made PHP collect the post ID into
 * `$args = ['args' => $post_id]` instead of positionally `$args = [$post_id]`, so
 * WordPress's `edit_post` meta-capability check could never find the target post and
 * always denied the request — for every user, including admins.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\ActionAppliers\ContentUpdateActionApplier;

function test_content_update_action_applier(): void {
    $applier = new ContentUpdateActionApplier();

    // Simulates a real WordPress capability check: only allowed to edit post 42
    // specifically. If the post ID is ever passed as anything other than the first
    // positional variadic argument, this handler won't see it and will deny.
    $can_edit_post_42 = static function (string $capability, mixed ...$args): bool {
        return 'edit_post' === $capability && 42 === ($args[0] ?? null);
    };

    // A user allowed to edit exactly this post can successfully apply the update.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = $can_edit_post_42;
    $post = new WP_Post();
    $post->ID = 42;
    $post->post_title = 'Original title';
    $GLOBALS['awpt_test_posts'][42] = $post;
    $result = $applier->apply(['post_id' => 42, 'post_title' => 'Updated title']);
    Assert::false(is_wp_error($result), 'apply() should succeed when the user can edit the target post');

    // A user who cannot edit a *different* post is correctly denied (not a false allow).
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = $can_edit_post_42;
    $result = $applier->apply(['post_id' => 99, 'post_title' => 'Updated title']);
    Assert::true(is_wp_error($result), 'apply() should fail when the user cannot edit the target post');

    if (is_wp_error($result)) {
        Assert::same(
            'awpt_cannot_edit_post',
            $result->get_error_code(),
            'permission failure should use the awpt_cannot_edit_post error code',
        );
    }

    // Missing post_id is rejected outright, regardless of capability.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = static fn(): bool => true;
    $result = $applier->apply(['post_title' => 'Updated title']);
    Assert::true(is_wp_error($result), 'apply() should fail without a post_id even if the user can edit posts');

    // Meta-only updates are valid when the user can edit the target post.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = $can_edit_post_42;
    $GLOBALS['awpt_test_post_meta_updates'] = [];
    $post = new WP_Post();
    $post->ID = 42;
    $GLOBALS['awpt_test_posts'][42] = $post;
    $result = $applier->apply([
        'post_id' => 42,
        'post_meta' => ['seo_title' => 'Updated SEO title'],
    ]);
    Assert::false(is_wp_error($result), 'apply() should succeed for meta-only updates');
    Assert::same(
        ['seo_title' => 'Updated SEO title'],
        $GLOBALS['awpt_test_post_meta_updates'][42] ?? null,
        'meta-only apply() should write the staged meta values',
    );

    // Block attribute updates are recomputed against the current post content and
    // preserve the surrounding serialized block markup.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = $can_edit_post_42;
    $post = new WP_Post();
    $post->ID = 42;
    $post->post_content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:image {"width":"120","id":9} --><figure>Image</figure><!-- /wp:image -->';
    $GLOBALS['awpt_test_posts'][42] = $post;
    $fingerprint = AWPT\Support\BlockTree::from_content($post->post_content)->normalized()[1]['fingerprint'] ?? '';
    $result = $applier->apply([
        'operation' => 'block_attrs_update',
        'post_id' => 42,
        'block_path' => '1',
        'expected_fingerprint' => $fingerprint,
        'attrs' => ['width' => '180'],
    ]);
    Assert::false(is_wp_error($result), 'apply() should succeed for a staged block attr update');
    Assert::true(
        str_contains($GLOBALS['awpt_test_posts'][42]->post_content, '<!-- wp:image {"width":"180","id":9} -->'),
        'block attr apply() should write the updated serialized block content',
    );

    // Template/global-styles content updates also detect intervening content edits.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = static fn(string $capability, mixed ...$args): bool => match (
        $capability
    ) {
        'edit_post' => 42 === ($args[0] ?? null),
        'edit_theme_options' => true,
        default => false,
    };
    $post = new WP_Post();
    $post->ID = 42;
    $post->post_content = '<!-- wp:paragraph --><p>live</p><!-- /wp:paragraph -->';
    $GLOBALS['awpt_test_posts'][42] = $post;
    $result = $applier->apply([
        'operation' => 'template_update',
        'post_id' => 42,
        'post_content' => '<!-- wp:paragraph --><p>proposed</p><!-- /wp:paragraph -->',
        'original_post_content' => '<!-- wp:paragraph --><p>stale</p><!-- /wp:paragraph -->',
    ]);
    Assert::true(is_wp_error($result), 'template apply() should fail when original content is stale');
    if (is_wp_error($result)) {
        Assert::same('awpt_action_conflict', $result->get_error_code(), 'stale template content uses conflict code');
    }
}

test_content_update_action_applier();
