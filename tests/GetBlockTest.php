<?php

/** Tests targeted saved-markup evidence. @package AWPT */

declare(strict_types=1);

use AWPT\Abilities\GetBlock;

function test_get_block_returns_exact_inner_html_editability_evidence(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 843;
    $post->post_type = 'page';
    $post->post_status = 'publish';
    $post->post_content = '<!-- wp:list {"ordered":true} --><ol><li>One</li><li>Two</li></ol><!-- /wp:list -->';
    $GLOBALS['awpt_test_posts'][843] = $post;

    $result = new GetBlock()->execute(['id' => 843, 'path' => '0']);

    Assert::false(is_wp_error($result), 'get-block should read the legacy list');
    Assert::same('<ol><li>One</li><li>Two</li></ol>', $result['inner_html'] ?? '', 'saved HTML is exact');
    Assert::same(true, $result['inner_html_editable'] ?? false, 'legacy leaf list is eligible');
    Assert::same(false, $result['inner_html_truncated'] ?? true, 'small saved HTML is complete');
}

function test_get_block_marks_nested_and_dynamic_blocks_ineligible(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 844;
    $post->post_type = 'page';
    $post->post_status = 'publish';
    $post->post_content =
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Nested.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '<!-- wp:latest-posts /-->';
    $GLOBALS['awpt_test_posts'][844] = $post;

    $nested = new GetBlock()->execute(['id' => 844, 'path' => '0']);
    Assert::same(false, $nested['inner_html_editable'] ?? true, 'nested container is not directly editable');
    Assert::true(
        str_contains((string) ($nested['inner_html_editability_reason'] ?? ''), 'does not support'),
        'nested unsupported block explains its restriction',
    );
    Assert::same('0.0', $nested['children'][0]['path'] ?? '', 'container returns an immediately actionable child path');
    Assert::same(
        64,
        strlen((string) ($nested['children'][0]['fingerprint'] ?? '')),
        'container returns the complete child fingerprint',
    );
    Assert::true(str_contains((string) ($nested['next'] ?? ''), 'child path'), 'container explains the next action');

    $dynamic = new GetBlock()->execute(['id' => 844, 'path' => '1']);
    Assert::same(false, $dynamic['inner_html_editable'] ?? true, 'dynamic block is not directly editable');
    Assert::true(
        str_contains((string) ($dynamic['inner_html_editability_reason'] ?? ''), 'does not support'),
        'dynamic block explains its restriction',
    );
}

test_get_block_returns_exact_inner_html_editability_evidence();
test_get_block_marks_nested_and_dynamic_blocks_ineligible();
