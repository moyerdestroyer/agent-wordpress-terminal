<?php

/**
 * Active-theme composition context contracts.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\SiteDesignContext;

function test_design_context_enriches_composition_queries_without_touching_unrelated_questions(): void {
    awpt_test_reset_state();
    $context = new SiteDesignContext();
    $query = $context->enrich_retrieval_query('Create a polished landing page for a neighborhood garden club.');

    Assert::true(str_contains($query, 'CivicPress'), 'composition retrieval should include the active theme name');
    Assert::true(str_contains($query, 'civicpress'), 'composition retrieval should include the stylesheet');
    Assert::true(str_contains($query, 'design patterns'), 'composition retrieval should include design intent');
    Assert::same(
        'How many users are registered?',
        $context->enrich_retrieval_query('How many users are registered?'),
        'non-design retrieval queries should remain unchanged',
    );
}

function test_design_context_resolves_parent_and_merged_tokens(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_template'] = 'civicpress-parent';
    $GLOBALS['awpt_test_theme_names']['civicpress-parent'] = 'CivicPress Parent';
    $context = new SiteDesignContext()->resolve();

    Assert::same('CivicPress', $context['theme_name'], 'active theme name should resolve');
    Assert::same('CivicPress Parent', $context['parent_theme_name'], 'parent name should resolve');
    Assert::same(
        ['civicpress/', 'civicpress-parent/'],
        $context['preferred_pattern_namespaces'],
        'child and parent namespaces should be preferred',
    );
    Assert::same(
        'primary',
        $context['design_tokens']['color_palette'][0]['slug'] ?? '',
        'merged theme tokens should be included',
    );
}

function test_design_context_uses_proportional_policy_levels(): void {
    awpt_test_reset_state();
    $context = new SiteDesignContext();

    Assert::same(
        SiteDesignContext::LEVEL_COMPOSITION,
        $context->request_level('Build a new landing page for our annual event.'),
        'new page should use composition policy',
    );
    Assert::same(
        SiteDesignContext::LEVEL_SECTION,
        $context->request_level('Add a call to action section below the schedule.'),
        'section additions should use section policy',
    );
    Assert::same(
        SiteDesignContext::LEVEL_TOKENS,
        $context->request_level('Adjust the heading color and spacing.'),
        'small style edits should use token policy',
    );
    Assert::same(
        SiteDesignContext::LEVEL_NONE,
        $context->request_level('List the recent posts.'),
        'non-design requests should remain unaffected',
    );
}

function test_design_context_prompt_summary_skips_tokens_when_requested(): void {
    awpt_test_reset_state();
    $context = new SiteDesignContext();
    $without = $context->prompt_summary('Hello', false);
    $with = $context->prompt_summary('Adjust the heading color and spacing.', true);

    Assert::true(str_contains($without, 'Active design authority'), 'summary should always name the theme');
    Assert::false(
        str_contains($without, 'Resolved WordPress design tokens:'),
        'tokens should be omitable for light turns',
    );
    Assert::true(
        str_contains($with, 'Resolved WordPress design tokens:'),
        'token-level design requests should still include tokens',
    );
}

test_design_context_enriches_composition_queries_without_touching_unrelated_questions();
test_design_context_resolves_parent_and_merged_tokens();
test_design_context_uses_proportional_policy_levels();
test_design_context_prompt_summary_skips_tokens_when_requested();
