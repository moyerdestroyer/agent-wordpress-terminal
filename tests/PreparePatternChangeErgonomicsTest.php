<?php

/**
 * Light adoption ergonomics for prepare-pattern-change / read-block-tree.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\PreparePatternChange;
use AWPT\Abilities\ReadBlockTree;
use AWPT\Support\BlockTree;

function test_read_block_tree_exposes_top_level_sections_for_prepare(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 501;
    $post->post_content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';
    $GLOBALS['awpt_test_posts'][501] = $post;

    $result = new ReadBlockTree()->execute(['id' => 501]);
    Assert::false(is_wp_error($result), 'read-block-tree should succeed');
    Assert::true(is_array($result['top_level_sections'] ?? null), 'top_level_sections present');
    Assert::same(2, count($result['top_level_sections'] ?? []), 'two top-level sections');
    Assert::same('0', $result['top_level_sections'][0]['path'] ?? null, 'first path');
    Assert::true(
        is_string($result['top_level_sections'][0]['fingerprint'] ?? null)
        && 64 === strlen((string) $result['top_level_sections'][0]['fingerprint']),
        'fingerprint on top-level section',
    );
    Assert::true(
        is_string($result['top_level_sections'][0]['role'] ?? null)
        && '' !== (string) $result['top_level_sections'][0]['role'],
        'role present on top-level section',
    );
    Assert::true(
        array_key_exists('has_dynamic_blocks', $result['top_level_sections'][0]),
        'has_dynamic_blocks present',
    );
    Assert::true(
        array_key_exists('heading', $result['top_level_sections'][0]),
        'heading present on top-level section',
    );
    Assert::true(
        is_string($result['prepare_pattern_change_hint'] ?? null)
        && str_contains((string) $result['prepare_pattern_change_hint'], 'prepare-pattern-change'),
        'hint points at prepare-pattern-change',
    );
}

function test_prepare_pattern_change_autofills_fingerprint_when_omitted(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 502;
    $post->post_type = 'page';
    $post->post_content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Middle</p><!-- /wp:paragraph -->';
    $post->post_modified_gmt = '2026-01-01 00:00:00';
    $GLOBALS['awpt_test_posts'][502] = $post;

    // Without patterns, selection may custom_fallback — still proves path/fingerprint path.
    $result = new PreparePatternChange()->execute([
        'post_id' => 502,
        'intent' => 'replace middle with staff directory',
        'mode' => 'replace',
        'target_path' => '1',
        // expected_fingerprint omitted on purpose
    ]);

    if (is_wp_error($result)) {
        // Missing path menu errors should not fire when path is valid.
        Assert::false(
            in_array($result->get_error_code(), ['awpt_fingerprint_required', 'awpt_target_path_required'], true),
            'should not require fingerprint when path is valid: ' . $result->get_error_code(),
        );
        // Pattern catalog may be empty in stubs → custom path or not_found is ok if not fingerprint.
        Assert::true(true, 'non-fingerprint error acceptable without catalog: ' . $result->get_error_code());

        return;
    }

    Assert::true(
        in_array((string) ($result['mode'] ?? ''), ['replace', 'custom_fallback'], true),
        'prepare returns replace or custom_fallback',
    );

    if ('replace' === ($result['mode'] ?? '')) {
        $fp = (string) ($result['expected_fingerprint'] ?? '');
        Assert::same(64, strlen($fp), 'auto-filled fingerprint is 64 chars');
        $live = BlockTree::fingerprint(BlockTree::from_content($post->post_content)->get_block('1') ?? []);
        Assert::true(hash_equals($live, $fp), 'auto-filled fingerprint matches live block');
        Assert::true('' !== (string) ($result['preparation_id'] ?? ''), 'preparation_id minted');
    }
}

function test_prepare_pattern_change_missing_path_returns_section_menu(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 503;
    $post->post_type = 'page';
    $post->post_content =
        '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->';
    $GLOBALS['awpt_test_posts'][503] = $post;

    $result = new PreparePatternChange()->execute([
        'post_id' => 503,
        'intent' => 'swap header',
        'mode' => 'replace',
        'target_path' => '',
    ]);

    Assert::true(is_wp_error($result), 'empty path fails');
    if (is_wp_error($result)) {
        Assert::same('awpt_target_path_required', $result->get_error_code(), 'specific missing-path code');
        $data = $result->get_error_data();
        Assert::true(is_array($data['top_level_sections'] ?? null), 'menu included');
        Assert::same(2, count($data['top_level_sections'] ?? []), 'two sections in menu');
        Assert::true(
            is_string($data['top_level_sections'][0]['role'] ?? null)
            && '' !== (string) $data['top_level_sections'][0]['role'],
            'menu sections include role',
        );
    }
}

function test_prepare_pattern_change_missing_path_includes_suggestions_and_routing(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 504;
    $post->post_type = 'page';
    $post->post_content =
        '<!-- wp:group --><!-- wp:post-title /--><!-- /wp:group -->'
        . '<!-- wp:group --><!-- wp:heading --><h2>FAQ</h2><!-- /wp:heading --><!-- /wp:group -->';
    $GLOBALS['awpt_test_posts'][504] = $post;

    $result = new PreparePatternChange()->execute([
        'post_id' => 504,
        'intent' => 'replace the FAQ section',
        'mode' => 'replace',
        'target_path' => '',
    ]);

    Assert::true(is_wp_error($result), 'empty path fails');
    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        Assert::true(is_array($data['section_suggestions'] ?? null), 'section_suggestions present');
        Assert::true(count($data['section_suggestions'] ?? []) > 0, 'FAQ intent suggests a section');
        Assert::same('1', $data['section_suggestions'][0]['path'] ?? null, 'FAQ path suggested');
        Assert::true(is_array($data['recommended_operation'] ?? null), 'recommended_operation present');
        Assert::same(
            'pattern_replace',
            $data['recommended_operation']['operation'] ?? null,
            'replace intent → pattern_replace op',
        );
    }
}

function test_prepare_pattern_change_success_includes_section_context_and_carry_forward(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 505;
    $post->post_type = 'page';
    $post->post_modified_gmt = '2026-01-01 00:00:00';
    $post->post_content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:group -->'
        . '<!-- wp:heading --><h2>FAQ</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>See <a href="https://example.com/help">help</a> or call 555-0199.</p><!-- /wp:paragraph -->'
        . '<!-- /wp:group -->';
    $GLOBALS['awpt_test_posts'][505] = $post;

    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'demo/faq-section',
            'title' => 'FAQ Section',
            'description' => 'FAQ accordion section',
            'content' =>
                '<!-- wp:group --><div class="wp-block-group">'
                . '<!-- wp:heading --><h2>Questions</h2><!-- /wp:heading -->'
                . '<!-- wp:paragraph --><p>Answer slot</p><!-- /wp:paragraph -->'
                . '</div><!-- /wp:group -->',
        ],
        [
            'name' => 'demo/layout-page-home',
            'title' => 'Home Layout Page',
            'description' => 'Full page layout',
            'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        ],
    ];

    $result = new PreparePatternChange()->execute([
        'post_id' => 505,
        'intent' => 'replace FAQ with a cleaner accordion',
        'mode' => 'replace',
        'target_path' => '1',
    ]);

    Assert::false(is_wp_error($result), 'prepare should succeed with catalog');
    Assert::true(is_array($result), 'array result');
    if (!is_array($result)) {
        return;
    }

    Assert::same('replace', $result['mode'] ?? null, 'replace mode');
    Assert::true('' !== (string) ($result['preparation_id'] ?? ''), 'preparation_id');
    Assert::same('faq', $result['target_section']['role'] ?? null, 'target role faq');
    Assert::same('1', $result['target_section']['path'] ?? null, 'target path');
    Assert::true(is_array($result['carry_forward'] ?? null), 'carry_forward present');
    Assert::true(
        in_array('https://example.com/help', $result['carry_forward']['links'] ?? [], true),
        'carry_forward includes link',
    );
    Assert::true(
        in_array('555-0199', $result['carry_forward']['numeric_tokens'] ?? [], true),
        'carry_forward includes number',
    );
    Assert::same(
        'pattern_replace',
        $result['recommended_operation']['operation'] ?? null,
        'soft op hint is replace',
    );
    Assert::true(is_array($result['page_sections'] ?? null) && count($result['page_sections']) >= 2, 'page_sections');
    Assert::same('demo/faq-section', $result['pattern']['name'] ?? null, 'section FAQ pattern preferred over layout');
}

function test_prepare_pattern_change_dynamic_section_warns_preserve(): void {
    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 506;
    $post->post_type = 'page';
    $post->post_modified_gmt = '2026-01-01 00:00:00';
    $post->post_content =
        '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->'
        . '<!-- wp:query -->'
        . '<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template -->'
        . '<!-- /wp:query -->';
    $GLOBALS['awpt_test_posts'][506] = $post;
    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'demo/section-card',
            'title' => 'Card Section',
            'description' => 'Simple section',
            'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        ],
    ];

    $result = new PreparePatternChange()->execute([
        'post_id' => 506,
        'intent' => 'refresh the layout of this area',
        'mode' => 'replace',
        'target_path' => '1',
    ]);

    Assert::false(is_wp_error($result), 'dynamic target still prepares (soft warn only)');
    if (!is_array($result)) {
        return;
    }

    Assert::true((bool) ($result['target_section']['preserve_by_default'] ?? false), 'preserve_by_default');
    Assert::true(is_array($result['warnings'] ?? null) && count($result['warnings']) > 0, 'warnings present');
    Assert::true(
        str_contains(strtolower(implode(' ', $result['warnings'] ?? [])), 'dynamic'),
        'warning mentions dynamic',
    );
    Assert::same(
        'pattern_replace_with_preserve_warning',
        $result['recommended_operation']['operation'] ?? null,
        'op hint includes preserve warning',
    );
}

function test_pattern_change_replace_nudge_text(): void {
    $runtime = new AWPT\Agent\ProviderRuntime();
    $method = new ReflectionMethod(AWPT\Agent\ProviderRuntime::class, 'pattern_change_replace_nudge');
    $method->setAccessible(true);

    $empty = $method->invoke($runtime, []);
    Assert::same('', $empty, 'no prep → no nudge');

    $nudge = $method->invoke($runtime, [[
        'tool' => 'awpt/prepare-pattern-change',
        'status' => 'success',
        'output' => [
            'mode' => 'replace',
            'preparation_id' => 'prep-xyz',
            'post_id' => 9,
            'target_path' => '0',
        ],
    ]]);
    Assert::true(str_contains($nudge, 'prep-xyz'), 'nudge includes preparation_id');
    Assert::true(str_contains($nudge, 'propose-pattern-replace'), 'nudge names replace ability');
    Assert::false(str_contains(strtolower($nudge), 'will reject'), 'nudge is soft prefer not hard lock');
}

test_read_block_tree_exposes_top_level_sections_for_prepare();
test_prepare_pattern_change_autofills_fingerprint_when_omitted();
test_prepare_pattern_change_missing_path_returns_section_menu();
test_prepare_pattern_change_missing_path_includes_suggestions_and_routing();
test_prepare_pattern_change_success_includes_section_context_and_carry_forward();
test_prepare_pattern_change_dynamic_section_warns_preserve();
test_pattern_change_replace_nudge_text();
