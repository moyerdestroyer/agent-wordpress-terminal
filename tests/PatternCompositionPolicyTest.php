<?php

/**
 * Unit tests for PatternCompositionPolicy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PatternCompositionPolicy;

function test_pattern_composition_policy_defaults_named_pattern_to_adapted(): void {
    $policy = new PatternCompositionPolicy();

    Assert::same(
        PatternCompositionPolicy::MODE_ADAPTED,
        $policy->resolve_mode('', 'theme/hero'),
        'omitted mode with a pattern name must default to adapted',
    );
    Assert::same(
        PatternCompositionPolicy::MODE_PREPEND,
        $policy->resolve_mode('prepend', 'theme/hero'),
        'explicit prepend must be preserved',
    );
    Assert::same(
        PatternCompositionPolicy::MODE_ADAPTED,
        $policy->resolve_mode('adapted', 'theme/hero'),
        'explicit adapted must be preserved',
    );
    Assert::same(
        PatternCompositionPolicy::MODE_PREPEND,
        $policy->resolve_mode('prepend', '', 'adapted'),
        'explicit request wins over existing payload mode',
    );
    Assert::same(
        PatternCompositionPolicy::MODE_PREPEND,
        $policy->resolve_mode('', 'theme/hero', 'prepend'),
        'revisions may keep an explicit existing prepend mode',
    );
    Assert::same(
        PatternCompositionPolicy::MODE_ADAPTED,
        $policy->resolve_mode('', '', 'adapted'),
        'existing adapted is preserved without a fresh pattern name',
    );
}

function test_pattern_composition_policy_prepends_only_for_short_tail(): void {
    $policy = new PatternCompositionPolicy();
    $pattern =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading --><h2>Demo heading</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $tail = '<!-- wp:paragraph --><p>Extra note after the pattern.</p><!-- /wp:paragraph -->';
    $filled =
        '<!-- wp:cover --><div class="wp-block-cover"><p>Real hero</p></div><!-- /wp:cover -->'
        . "\n"
        . '<!-- wp:paragraph --><p>Real body copy for the page.</p><!-- /wp:paragraph -->';

    Assert::true(
        $policy->should_prepend(PatternCompositionPolicy::MODE_PREPEND, $pattern, $tail),
        'prepend should inject the pattern before a short body tail',
    );
    Assert::false(
        $policy->should_prepend(PatternCompositionPolicy::MODE_ADAPTED, $pattern, $filled),
        'adapted mode must never prepend',
    );
    Assert::false(
        $policy->should_prepend(PatternCompositionPolicy::MODE_PREPEND, $pattern, $pattern . "\n\n" . $tail),
        'already-prefixed content should not be prepended again',
    );

    $ok = $policy->conflict_if_prepend_would_duplicate(PatternCompositionPolicy::MODE_PREPEND, $pattern, $tail);
    Assert::same(null, $ok, 'short single-block tail under prepend is allowed');

    $conflict = $policy->conflict_if_prepend_would_duplicate(PatternCompositionPolicy::MODE_PREPEND, $pattern, $filled);
    Assert::true(is_wp_error($conflict), 'full filled layout under prepend must fail closed');
    Assert::same(
        'awpt_pattern_mode_mismatch',
        is_wp_error($conflict) ? $conflict->get_error_code() : '',
        'prepend+full layout uses pattern mode mismatch code',
    );
}

function test_pattern_composition_policy_detects_raw_pattern_twins(): void {
    $policy = new PatternCompositionPolicy();
    $pattern =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>A short heading to introduce or highlight a key concept.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $filled =
        '<!-- wp:cover --><div class="wp-block-cover"><p>Real campaign hero</p></div><!-- /wp:cover -->'
        . '<!-- wp:paragraph --><p>Custom section about the product.</p><!-- /wp:paragraph -->';
    $twin = $pattern . "\n\n" . $filled;

    $error = $policy->conflict_if_raw_pattern_twin($pattern, $twin);
    Assert::true(is_wp_error($error), 'raw pattern plus filled layout is a twin');
    Assert::same(
        'awpt_pattern_content_twin',
        is_wp_error($error) ? $error->get_error_code() : '',
        'twin detection uses awpt_pattern_content_twin',
    );

    Assert::same(null, $policy->conflict_if_raw_pattern_twin($pattern, $pattern), 'pattern alone is not a twin');
    Assert::same(
        null,
        $policy->conflict_if_raw_pattern_twin(
            $pattern,
            $pattern . "\n\n<!-- wp:paragraph --><p>Short note.</p><!-- /wp:paragraph -->",
        ),
        'pattern plus a short single-block tail is not a twin',
    );

    $stripped = $policy->strip_raw_pattern_twin($pattern, $twin);
    Assert::true(is_string($stripped), 'twin strip should return the filled remainder');
    Assert::true(
        is_string($stripped) && str_contains($stripped, 'Real campaign hero'),
        'stripped twin should keep the customized layout',
    );
    Assert::true(
        is_string($stripped) && !str_contains($stripped, 'A short heading to introduce'),
        'stripped twin should drop the raw pattern copy',
    );
    Assert::same(
        null,
        $policy->strip_raw_pattern_twin($pattern, $pattern),
        'pattern-only content is not strip-repaired',
    );
}

test_pattern_composition_policy_defaults_named_pattern_to_adapted();
test_pattern_composition_policy_prepends_only_for_short_tail();
test_pattern_composition_policy_detects_raw_pattern_twins();
