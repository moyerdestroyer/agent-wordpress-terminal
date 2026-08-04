<?php

/** Template-aware post-title strategy. @package AWPT */

declare(strict_types=1);

use AWPT\Support\ThemePostTitleStrategy;

function test_theme_title_strategy_resolves_nested_pattern_post_title(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [[
        'name' => 'theme/page-shell',
        'content' => '<!-- wp:group --><div><!-- wp:post-title --><!-- /wp:post-title --></div><!-- /wp:group -->',
    ]];
    $strategy = new ThemePostTitleStrategy();
    $method = new ReflectionMethod(ThemePostTitleStrategy::class, 'content_has_post_title');

    Assert::true(
        $method->invoke($strategy, '<!-- wp:pattern {"slug":"theme/page-shell"} --><!-- /wp:pattern -->'),
        'a post-title supplied through a template pattern should be detected',
    );
}

function test_theme_title_strategy_detects_content_h1(): void {
    $strategy = new ThemePostTitleStrategy();
    Assert::true(
        $strategy->content_has_h1('<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'),
        'an explicit level-1 core heading should satisfy a titleless template',
    );
    Assert::false(
        $strategy->content_has_h1('<!-- wp:heading {"level":2} --><h2>Section</h2><!-- /wp:heading -->'),
        'section headings must not be mistaken for a document H1',
    );
}

test_theme_title_strategy_resolves_nested_pattern_post_title();
test_theme_title_strategy_detects_content_h1();
