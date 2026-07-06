<?php

/**
 * Tests for AWPT\Support\ContentTargetResolver.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\ContentTargetResolver;

function awpt_test_post(int $id, string $title, string $slug, string $type = 'page'): WP_Post
{
    $post = new WP_Post();
    $post->ID = $id;
    $post->post_title = $title;
    $post->post_name = $slug;
    $post->post_type = $type;
    $post->post_status = 'publish';
    $GLOBALS['awpt_test_posts'][$id] = $post;

    return $post;
}

function test_content_target_resolver_exact_references(): void
{
    awpt_test_reset_state();
    awpt_test_post(42, 'About', 'about');
    awpt_test_post(43, 'Marketing Template', 'marketing-template', 'wp_template');
    $GLOBALS['awpt_test_url_to_postid']['https://example.test/about/'] = 42;

    $resolver = new ContentTargetResolver();

    $by_id = $resolver->resolve('42');
    Assert::same('resolved', $by_id['status'], 'numeric IDs should resolve');
    Assert::same(42, $by_id['post_id'] ?? null, 'numeric IDs should return the matching post ID');

    $by_slug = $resolver->resolve('about');
    Assert::same('resolved', $by_slug['status'], 'slugs should resolve');
    Assert::same(42, $by_slug['post_id'] ?? null, 'slugs should return the matching post ID');

    $by_url = $resolver->resolve('https://example.test/about/');
    Assert::same('resolved', $by_url['status'], 'URLs should resolve through url_to_postid');
    Assert::same(42, $by_url['post_id'] ?? null, 'URLs should return the matching post ID');

    $filtered = $resolver->resolve('42', 'wp_template');
    Assert::same('missing', $filtered['status'], 'post type filters should apply to exact ID matches');

    $template = $resolver->resolve('marketing-template', 'wp_template');
    Assert::same('resolved', $template['status'], 'post type filters should allow matching template slugs');
    Assert::same(43, $template['post_id'] ?? null, 'template slug should return template post ID');
}

test_content_target_resolver_exact_references();
