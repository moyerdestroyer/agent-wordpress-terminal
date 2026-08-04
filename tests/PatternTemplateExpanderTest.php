<?php

/** Pattern references become editable markup without model serialization. @package AWPT */

declare(strict_types=1);

use AWPT\Domain\PatternTemplateExpander;

function test_pattern_template_expander_resolves_nested_registered_patterns(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'demo/page',
            'title' => 'Page',
            'content' => '<!-- wp:pattern {"slug":"demo/hero"} /--><!-- wp:group --><div class="wp-block-group"><!-- wp:pattern {"slug":"demo/cards"} /--></div><!-- /wp:group -->',
        ],
        [
            'name' => 'demo/hero',
            'title' => 'Hero',
            'content' => '<!-- wp:cover --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"><!-- wp:heading --><h2>Hero copy</h2><!-- /wp:heading --></div></div><!-- /wp:cover -->',
        ],
        [
            'name' => 'demo/cards',
            'title' => 'Cards',
            'content' => '<!-- wp:columns --><div class="wp-block-columns"></div><!-- /wp:columns -->',
        ],
    ];

    $expanded = new PatternTemplateExpander()->expand('demo/page');

    Assert::true(is_string($expanded), 'registered pattern references should expand');
    Assert::false(
        is_string($expanded) && str_contains($expanded, 'wp:pattern'),
        'expanded markup should not retain pattern reference blocks',
    );
    Assert::true(
        is_string($expanded) && str_contains($expanded, 'Hero copy'),
        'nested pattern copy should be editable',
    );
    Assert::true(
        is_string($expanded) && str_contains($expanded, 'wp:columns'),
        'nested layout blocks should be preserved',
    );
}

function test_pattern_template_expander_rejects_cycles(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_registered_patterns'] = [[
        'name' => 'demo/cycle',
        'title' => 'Cycle',
        'content' => '<!-- wp:pattern {"slug":"demo/cycle"} /-->',
    ]];

    $expanded = new PatternTemplateExpander()->expand('demo/cycle');
    Assert::same(
        'awpt_pattern_expansion_cycle',
        is_wp_error($expanded) ? $expanded->get_error_code() : '',
        'recursive pattern dependencies must fail closed',
    );
}

test_pattern_template_expander_resolves_nested_registered_patterns();
test_pattern_template_expander_rejects_cycles();
