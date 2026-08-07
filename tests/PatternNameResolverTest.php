<?php

/**
 * Pattern name alias resolution.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PatternCatalog;
use AWPT\Support\PatternNameResolver;

function test_pattern_name_resolver_aliases_page_header_and_documentation(): void {
    awpt_test_reset_state();

    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'civicpress/header-page',
            'title' => 'Basic Page Header',
            'content' => '<!-- wp:heading --><h1>Title</h1><!-- /wp:heading -->',
        ],
        [
            'name' => 'civicpress/layout-page-documentation',
            'title' => 'Documentation Page',
            'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        ],
        [
            'name' => 'civicpress/section-team-member-directory',
            'title' => 'Team Member Directory Section',
            'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        ],
    ];

    $aliases = [
        'civicpress/page-header' => 'civicpress/header-page',
        'civicpress/documentation-page' => 'civicpress/layout-page-documentation',
        'civicpress/team-directory' => 'civicpress/section-team-member-directory',
    ];
    $resolver = new PatternNameResolver(new PatternCatalog(), ['civicpress'], $aliases);

    $fromHeader = $resolver->resolve('civicpress/page-header');
    Assert::true(null !== $fromHeader, 'page-header alias should resolve');
    Assert::same('civicpress/header-page', $fromHeader['resolved_name'] ?? '', 'canonical header-page');
    Assert::same('civicpress/page-header', $fromHeader['resolved_from'] ?? '', 'resolved_from should record the alias');

    $fromDocs = $resolver->resolve('civicpress/documentation-page');
    Assert::same(
        'civicpress/layout-page-documentation',
        $fromDocs['resolved_name'] ?? '',
        'documentation-page alias should resolve',
    );

    $exact = $resolver->resolve('civicpress/header-page');
    Assert::true(is_array($exact) && array_key_exists('resolved_from', $exact), 'exact resolve payload present');
    Assert::same(null, $exact['resolved_from'], 'exact matches should not set resolved_from');

    $team = $resolver->resolve('civicpress/team-directory');
    Assert::same(
        'civicpress/section-team-member-directory',
        $team['resolved_name'] ?? '',
        'team-directory pack alias should resolve',
    );
}

function test_pattern_name_resolver_uses_injected_namespace_not_hardcoded_theme(): void {
    awpt_test_reset_state();

    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'fixture-theme/header-page',
            'title' => 'Header',
            'content' => '<!-- wp:heading --><h1>Title</h1><!-- /wp:heading -->',
        ],
    ];

    $resolver = new PatternNameResolver(new PatternCatalog(), ['fixture-theme'], []);
    $resolved = $resolver->resolve('page-header');

    Assert::true(null !== $resolved, 'bare page-header should resolve under injected namespace');
    Assert::same('fixture-theme/header-page', $resolved['resolved_name'] ?? '', 'namespace comes from pack, not civicpress');
}

test_pattern_name_resolver_aliases_page_header_and_documentation();
test_pattern_name_resolver_uses_injected_namespace_not_hardcoded_theme();
