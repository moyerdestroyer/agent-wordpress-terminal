<?php

/**
 * Tests for AWPT\Support\ContentListService.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\ContentListService;

function awpt_test_list_post(
    int $id,
    string $title,
    string $slug,
    string $type = 'post',
    string $status = 'publish',
    string $modified = '2026-01-01 12:00:00',
    string $created = '2026-01-01 10:00:00',
    int $author_id = 1,
    string $content = '',
    string $excerpt = '',
): WP_Post {
    $post = new WP_Post();
    $post->ID = $id;
    $post->post_title = $title;
    $post->post_name = $slug;
    $post->post_type = $type;
    $post->post_status = $status;
    $post->post_modified_gmt = $modified;
    $post->post_date_gmt = $created;
    $post->post_author = $author_id;
    $post->post_content = $content;
    $post->post_excerpt = $excerpt;
    $GLOBALS['awpt_test_posts'][$id] = $post;

    return $post;
}

function awpt_test_list_user(int $id, string $login, string $display_name): WP_User {
    $user = new WP_User();
    $user->ID = $id;
    $user->user_login = $login;
    $user->user_nicename = $login;
    $user->display_name = $display_name;
    $user->user_email = $login . '@example.test';
    $GLOBALS['awpt_test_users'][$id] = $user;

    return $user;
}

function test_content_list_service_counts_posts_by_status(): void {
    awpt_test_reset_state();
    awpt_test_list_post(1, 'Published One', 'published-one', 'post', 'publish', '2026-01-03 12:00:00');
    awpt_test_list_post(2, 'Draft One', 'draft-one', 'post', 'draft', '2026-01-02 12:00:00');
    awpt_test_list_post(3, 'About', 'about', 'page', 'publish', '2026-01-04 12:00:00');

    $result = new ContentListService()->list(['post_type' => 'post', 'limit' => 10]);

    Assert::same(2, $result['total'] ?? null, 'post inventory should count only posts');
    Assert::same(2, $result['count'] ?? null, 'post inventory should return both posts');
    Assert::same(1, $result['totals_by_status']['publish'] ?? null, 'publish totals should include published posts');
    Assert::same(1, $result['totals_by_status']['draft'] ?? null, 'draft totals should include draft posts');
    Assert::same('Published One', $result['items'][0]['title'] ?? null, 'items should be ordered by modified date');
}

function test_content_list_service_filters_by_status(): void {
    awpt_test_reset_state();
    awpt_test_list_post(1, 'Published One', 'published-one', 'post', 'publish');
    awpt_test_list_post(2, 'Draft One', 'draft-one', 'post', 'draft');

    $result = new ContentListService()->list([
        'post_type' => 'post',
        'status' => 'draft',
        'limit' => 10,
    ]);

    Assert::same(1, $result['total'] ?? null, 'status filter should limit totals to drafts');
    Assert::same('Draft One', $result['items'][0]['title'] ?? null, 'status filter should return draft items only');
}

function test_content_list_service_honors_read_post_capability(): void {
    awpt_test_reset_state();
    awpt_test_list_post(1, 'Readable', 'readable');
    awpt_test_list_post(2, 'Private', 'private', 'post', 'private');
    $GLOBALS['awpt_test_current_user_can'] = static function (string $capability, mixed ...$args): bool {
        if ('read_post' === $capability) {
            return 1 === (int) ($args[0] ?? 0);
        }

        return true;
    };

    $result = new ContentListService()->list(['post_type' => 'post', 'limit' => 10]);

    Assert::same(2, $result['total'] ?? null, 'total should still reflect query results');
    Assert::same(1, $result['count'] ?? null, 'unreadable posts should be omitted from items');
    Assert::same('Readable', $result['items'][0]['title'] ?? null, 'readable posts should remain in items');
}

function test_content_list_service_filters_by_author_and_includes_metadata(): void {
    awpt_test_reset_state();
    awpt_test_list_user(7, 'ryan', 'Ryan');
    awpt_test_list_user(8, 'alex', 'Alex');
    awpt_test_list_post(1, 'Ryan Post', 'ryan-post', author_id: 7, excerpt: 'Short excerpt');
    awpt_test_list_post(2, 'Alex Post', 'alex-post', author_id: 8);

    $result = new ContentListService()->list([
        'post_type' => 'post',
        'author' => 'ryan',
        'limit' => 10,
    ]);

    Assert::same(1, $result['total'] ?? null, 'author filter should limit results');
    Assert::same('Ryan', $result['items'][0]['author'] ?? null, 'items should include author display name');
    Assert::same(7, $result['items'][0]['author_id'] ?? null, 'items should include author ID');
    Assert::same('Short excerpt', $result['items'][0]['excerpt'] ?? null, 'items should include excerpt metadata');
    Assert::same(7, $result['filters']['author_id'] ?? null, 'filters should echo resolved author ID');
}

function test_content_list_service_supports_search_sort_and_pagination(): void {
    awpt_test_reset_state();
    awpt_test_list_post(1, 'Alpha Update', 'alpha-update', created: '2026-01-01 10:00:00');
    awpt_test_list_post(2, 'Beta Update', 'beta-update', created: '2026-01-03 10:00:00');
    awpt_test_list_post(3, 'Gamma Story', 'gamma-story', created: '2026-01-02 10:00:00');

    $search = new ContentListService()->list([
        'post_type' => 'post',
        'search' => 'update',
        'limit' => 10,
    ]);
    Assert::same(2, $search['total'] ?? null, 'search should match title text');

    $sorted = new ContentListService()->list([
        'post_type' => 'post',
        'orderby' => 'title',
        'order' => 'ASC',
        'limit' => 10,
    ]);
    Assert::same('Alpha Update', $sorted['items'][0]['title'] ?? null, 'title sort should order alphabetically');

    $paged = new ContentListService()->list([
        'post_type' => 'post',
        'orderby' => 'title',
        'order' => 'ASC',
        'offset' => 1,
        'limit' => 1,
    ]);
    Assert::same(3, $paged['total'] ?? null, 'pagination should preserve total count');
    Assert::same(1, $paged['count'] ?? null, 'pagination should return one item per page');
    Assert::true((bool) ($paged['has_more'] ?? false), 'pagination should indicate more results');
    Assert::same('Beta Update', $paged['items'][0]['title'] ?? null, 'pagination offset should skip first item');
}

function test_content_list_service_includes_totals_by_type_for_all(): void {
    awpt_test_reset_state();
    awpt_test_list_post(1, 'Post One', 'post-one');
    awpt_test_list_post(2, 'About', 'about', 'page');

    $result = new ContentListService()->list(['post_type' => 'all', 'limit' => 10]);

    Assert::same(1, $result['totals_by_type']['post'] ?? null, 'type totals should include posts');
    Assert::same(1, $result['totals_by_type']['page'] ?? null, 'type totals should include pages');
}

test_content_list_service_counts_posts_by_status();
test_content_list_service_filters_by_status();
test_content_list_service_honors_read_post_capability();
test_content_list_service_filters_by_author_and_includes_metadata();
test_content_list_service_supports_search_sort_and_pagination();
function test_content_list_service_skips_totals_when_filtered(): void {
    awpt_test_reset_state();
    awpt_test_list_post(1, 'Published One', 'published-one');
    awpt_test_list_post(2, 'Draft One', 'draft-one', 'post', 'draft');

    $result = new ContentListService()->list([
        'post_type' => 'post',
        'author' => 'ryan',
        'limit' => 10,
    ]);

    Assert::same([], $result['totals_by_status'] ?? null, 'filtered lists should skip status totals by default');
    Assert::false((bool) ($result['filters']['include_totals'] ?? true), 'author filter should disable include_totals');
}

test_content_list_service_includes_totals_by_type_for_all();
test_content_list_service_skips_totals_when_filtered();
