<?php

/**
 * Theme-native pattern ownership and ordering.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PatternCatalog;

function awpt_pattern_fixture(string $name, string $title, array $post_types = []): array {
    return [
        'name' => $name,
        'title' => $title,
        'description' => 'Hero landing layout',
        'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        'categories' => ['featured'],
        'blockTypes' => [],
        'postTypes' => $post_types,
    ];
}

function test_pattern_catalog_prefers_theme_native_patterns_after_relevance(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [
        awpt_pattern_fixture('core/hero', 'Core Hero'),
        awpt_pattern_fixture('plugin/hero', 'Plugin Hero'),
        awpt_pattern_fixture('civicpress/header-hero', 'CivicPress Hero'),
    ];
    $patterns = new PatternCatalog()->list('hero', 10, 'page');

    Assert::same('civicpress/header-hero', $patterns[0]['name'] ?? '', 'active-theme pattern should rank first');
    Assert::same('active_theme', $patterns[0]['owner'] ?? '', 'active pattern should expose ownership');
    Assert::same('compatible', $patterns[0]['compatibility'] ?? '', 'general pattern should fit a page');
    Assert::same('core', $patterns[1]['owner'] ?? '', 'Core should rank before other registered fallbacks');
}

function test_pattern_catalog_recognizes_parent_reusable_and_incompatible_patterns(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_template'] = 'civicpress-parent';
    $GLOBALS['awpt_test_registered_patterns'] = [
        awpt_pattern_fixture('civicpress-parent/hero', 'Parent Hero'),
        awpt_pattern_fixture('civicpress/post-only', 'Post Hero', ['post']),
    ];
    $patterns = new PatternCatalog()->list('hero', 10, 'page');

    Assert::same('parent_theme', $patterns[0]['owner'] ?? '', 'parent pattern should be theme-native');
    Assert::same('compatible', $patterns[0]['compatibility'] ?? '', 'parent pattern should fit the page');
    Assert::same('incompatible', $patterns[1]['compatibility'] ?? '', 'post-only pattern should be marked');
    Assert::true(new PatternCatalog()->has_site_native_patterns('page'), 'parent pattern should satisfy policy');
}

test_pattern_catalog_prefers_theme_native_patterns_after_relevance();
test_pattern_catalog_recognizes_parent_reusable_and_incompatible_patterns();

function test_pattern_catalog_exposes_composition_scope_and_bounded_dependencies(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [[
        'name' => 'civicpress/layout-page-campaign',
        'title' => 'Campaign Landing Page',
        'content' => '<!-- wp:civicpress/card {"className":"campaign-card"} /-->',
        'categories' => ['page'],
    ]];
    $catalog = new PatternCatalog();
    $pattern = $catalog->find('civicpress/layout-page-campaign');
    $summary = $catalog->summary(is_array($pattern) ? $pattern : []);
    $dependencies = $catalog->design_dependencies((string) ($pattern['content'] ?? ''));

    Assert::same('layout', $summary['composition_scope'] ?? '', 'full-page pattern should be identified');
    Assert::true(
        in_array('civicpress/card', $dependencies['custom_block_names'] ?? [], true),
        'custom block namespace should be visible',
    );
    Assert::true($dependencies['requires_theme_research'] ?? false, 'custom dependency should permit research');
}

test_pattern_catalog_exposes_composition_scope_and_bounded_dependencies();
