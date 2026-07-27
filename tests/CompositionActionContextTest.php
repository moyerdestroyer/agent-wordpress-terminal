<?php

/**
 * Server-generated design evidence on action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Database\ActionPayloadSanitizer;
use AWPT\Support\CompositionActionContext;

function test_composition_action_context_records_verified_theme_and_fallback_evidence(): void {
    awpt_test_reset_state();
    $payload = new CompositionActionContext()->enrich([
        'operation' => 'new_post',
        'post_id' => 0,
        'post_type' => 'page',
        'post_title' => 'Garden Club',
        'post_content' => '<!-- wp:paragraph --><p>Welcome.</p><!-- /wp:paragraph -->',
        'pattern_fallback_reason' => 'No registered pattern fits the event calendar.',
        'decision_trace' => ['Reviewed the supplied brief.'],
    ]);
    $clean = new ActionPayloadSanitizer()->sanitize($payload);
    $context = is_array($clean['composition_context'] ?? null) ? $clean['composition_context'] : [];

    Assert::same('CivicPress', $context['theme_name'] ?? '', 'action should record active theme');
    Assert::same('civicpress', $context['stylesheet'] ?? '', 'action should record stylesheet');
    Assert::same('custom', $context['pattern_owner'] ?? '', 'custom composition should be explicit');
    Assert::true((bool) ($context['fallback_used'] ?? false), 'custom composition should be marked as fallback');
    Assert::true(
        in_array(
            'Design basis: active theme CivicPress (civicpress); theme-native composition preferred.',
            $clean['decision_trace'] ?? [],
            true,
        ),
        'server-derived design basis should be appended to the compact trace',
    );
}

test_composition_action_context_records_verified_theme_and_fallback_evidence();
