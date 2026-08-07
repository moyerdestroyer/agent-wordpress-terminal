<?php

/**
 * Soft theme-native pattern fallback policy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PatternCatalog;
use AWPT\Support\PatternFallbackPolicy;
use AWPT\Support\PatternFirstPolicy;

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

function test_pattern_fallback_policy_requires_structured_unfit_not_a_vague_reason(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [
        awpt_fallback_pattern_fixture('civicpress/header-hero', 'CivicPress Hero'),
    ];
    $policy = new PatternFallbackPolicy();
    $catalog = new PatternCatalog();

    // Pattern-first is soft: Core custom composition is allowed without unfit ceremony.
    Assert::same(
        null,
        $policy->validate($catalog, 'page', 'core', ''),
        'unexplained Core fallback is allowed (pattern-first is advisory)',
    );
    Assert::same(
        null,
        $policy->validate(
            $catalog,
            'page',
            'core',
            'The theme patterns do not support the required map.',
        ),
        'a reason without pattern_unfit_code is allowed',
    );
    Assert::same(
        null,
        $policy->validate(
            $catalog,
            'page',
            'core',
            'civicpress/header-hero is a hero cover; this draft needs an interactive map block the theme patterns do not provide.',
            PatternFirstPolicy::CODE_SCOPE_MISMATCH,
        ),
        'structured scope_mismatch should preserve agent choice',
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

test_pattern_fallback_policy_requires_structured_unfit_not_a_vague_reason();
test_pattern_fallback_policy_does_not_block_sites_without_native_patterns();
