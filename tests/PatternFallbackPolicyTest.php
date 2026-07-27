<?php

/**
 * Soft theme-native pattern fallback policy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PatternCatalog;
use AWPT\Support\PatternFallbackPolicy;

function awpt_fallback_pattern_fixture(string $name, string $title): array {
    return [
        'name' => $name,
        'title' => $title,
        'description' => 'Hero landing layout',
        'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        'categories' => ['featured'],
        'blockTypes' => [],
        'postTypes' => [],
    ];
}

function test_pattern_fallback_policy_requires_only_an_explanation_not_a_theme_pattern(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [
        awpt_fallback_pattern_fixture('civicpress/header-hero', 'CivicPress Hero'),
    ];
    $policy = new PatternFallbackPolicy();
    $catalog = new PatternCatalog();
    $missing_reason = $policy->validate($catalog, 'page', 'core', '');

    Assert::true(is_wp_error($missing_reason), 'unexplained Core fallback should be recoverable');
    Assert::same(
        'awpt_pattern_fallback_reason_required',
        $missing_reason instanceof WP_Error ? $missing_reason->get_error_code() : '',
        'fallback error should be specific',
    );
    Assert::same(
        null,
        $policy->validate($catalog, 'page', 'core', 'The theme patterns do not support the required map.'),
        'a concrete fallback reason should preserve agent choice',
    );
    Assert::same(
        null,
        $policy->validate($catalog, 'page', 'active_theme', ''),
        'theme-native choice should not need explanation',
    );
}

function test_pattern_fallback_policy_does_not_block_sites_without_native_patterns(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [
        awpt_fallback_pattern_fixture('core/hero', 'Core Hero'),
    ];

    Assert::same(
        null,
        new PatternFallbackPolicy()->validate(new PatternCatalog(), 'page', 'core', ''),
        'Core composition should work without ceremony when no native pattern exists',
    );
}

test_pattern_fallback_policy_requires_only_an_explanation_not_a_theme_pattern();
test_pattern_fallback_policy_does_not_block_sites_without_native_patterns();
