<?php

/**
 * Tests for AWPT\Support\ContentSearchService.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\ContentSearchService;

function test_content_search_hides_staging_drafts(): void {
    awpt_test_reset_state();

    $staging = new WP_Post();
    $staging->ID = 41;
    $staging->post_title = 'How to Use CivicPress';
    $staging->post_name = 'how-to-use-civicpress';
    $staging->post_type = 'post';
    $staging->post_status = 'draft';
    $GLOBALS['awpt_test_posts'][41] = $staging;
    $GLOBALS['awpt_test_post_meta_updates'][41]['_awpt_staging_draft'] = 1;

    $search = new ContentSearchService();
    $by_id = $search->search(['query' => '41']);
    $by_title = $search->search(['query' => 'How to Use CivicPress']);
    $by_slug = $search->search(['query' => 'how-to-use-civicpress']);

    Assert::same(0, $by_id['count'], 'staging previews should not resolve by ID');
    Assert::same(0, $by_title['count'], 'staging previews should not appear in text search');
    Assert::same(0, $by_slug['count'], 'staging previews should not resolve by slug');
}

test_content_search_hides_staging_drafts();
