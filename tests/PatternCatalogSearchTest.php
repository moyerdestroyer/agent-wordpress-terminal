<?php

/**
 * Pattern search token/synonym matching.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PatternCatalog;

function test_pattern_catalog_matches_docs_to_documentation(): void {
    $catalog = new PatternCatalog();
    $method = new ReflectionMethod(PatternCatalog::class, 'matches');
    $method->setAccessible(true);

    $documentation = [
        'name' => 'civicpress/layout-page-documentation',
        'title' => 'Documentation Page',
        'description' => 'Use for reference or documentation pages.',
        'categories' => ['content_layouts'],
    ];
    $hero = [
        'name' => 'civicpress/header-hero',
        'title' => 'Hero Header',
        'description' => 'A large hero.',
        'categories' => ['headers'],
    ];

    Assert::true(
        (bool) $method->invoke($catalog, $documentation, 'docs'),
        'search "docs" should match Documentation Page',
    );
    Assert::true(
        (bool) $method->invoke($catalog, $documentation, 'documentation'),
        'search "documentation" should match Documentation Page',
    );
    Assert::false(
        (bool) $method->invoke($catalog, $hero, 'docs'),
        'search "docs" should not match an unrelated hero pattern',
    );
    Assert::true(
        (bool) $method->invoke(
            $catalog,
            [
                'name' => 'civicpress/section-two-column-toc',
                'title' => 'Two Column TOC',
                'description' => '',
                'categories' => [],
            ],
            'toc',
        ),
        'search "toc" should match two-column-toc',
    );
}

test_pattern_catalog_matches_docs_to_documentation();
