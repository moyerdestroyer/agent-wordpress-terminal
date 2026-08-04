<?php

/** Compact draft routing and schema contracts. @package AWPT */

declare(strict_types=1);

use AWPT\Abilities\PreparePatternDraft;
use AWPT\Abilities\ProposePatternedPost;
use AWPT\Domain\PatternEditableSlots;

function test_pattern_draft_extracts_general_structural_requirements_from_specific_prompt(): void {
    $ability = new PreparePatternDraft();
    $method = new ReflectionMethod(PreparePatternDraft::class, 'supporting_requirements');
    $requirements = $method->invoke(
        $ability,
        'Make a Commander news page with 4 images. Explain the benefits compared to Modern.',
        4,
    );

    Assert::true(
        in_array('comparison parallel topics', $requirements, true),
        'comparison language should request a parallel-topic section without subject-specific prompting',
    );
    Assert::same(
        1,
        count($requirements),
        'overlapping comparison, benefits, explanation, and image requests should not create repetitive sections',
    );
}

function test_pattern_draft_does_not_invent_structural_facets_for_vague_polish_prompt(): void {
    $ability = new PreparePatternDraft();
    $method = new ReflectionMethod(PreparePatternDraft::class, 'supporting_requirements');
    $requirements = $method->invoke($ability, 'Make that Commander page look good.', 0);

    Assert::false(
        in_array('comparison parallel topics', $requirements, true)
        || in_array('benefits features', $requirements, true)
        || in_array('explanation information', $requirements, true),
        'a vague visual request should not invent comparisons, benefits, or documentation sections',
    );
}

function test_compact_pattern_schema_supports_unbounded_creation_and_in_place_revision(): void {
    awpt_test_reset_state();
    new ProposePatternedPost()->register();
    $ability = wp_get_ability('awpt/propose-patterned-post');
    $schema = $ability?->get_input_schema();
    $properties = is_array($schema) && is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    $required = is_array($schema) && is_array($schema['required'] ?? null) ? $schema['required'] : [];
    $pattern_names = is_array($properties['pattern_names'] ?? null) ? $properties['pattern_names'] : [];
    $media_placements = is_array($properties['media_placements'] ?? null) ? $properties['media_placements'] : [];
    $media_items = is_array($media_placements['items'] ?? null) ? $media_placements['items'] : [];
    $media_properties = is_array($media_items['properties'] ?? null) ? $media_items['properties'] : [];

    Assert::same(
        'integer',
        is_array($properties['action_id'] ?? null) ? $properties['action_id']['type'] ?? '' : '',
        'the compact surface should revise an existing staged proposal without resending Gutenberg markup',
    );
    Assert::same('array', $pattern_names['type'] ?? '', 'ordered multi-pattern composition should be supported');
    Assert::false(isset($pattern_names['maxItems']), 'compact composition must not impose an arbitrary section limit');
    Assert::false(
        in_array('pattern_name', $required, true),
        'the ordered pattern_names list should be sufficient without a duplicate primary field',
    );
    Assert::true(
        in_array('featured_cover', $media_properties['placement']['enum'] ?? [], true),
        'compact composition should support semantic Cover background placement',
    );
    Assert::false(
        in_array('position', $media_items['required'] ?? [], true),
        'a semantic media slot should not require an unrelated insertion position',
    );
}

function test_custom_pattern_fallback_preserves_requested_media_inventory(): void {
    awpt_test_reset_state();
    awpt_test_list_post(88, 'image', 'image', 'attachment', 'inherit');
    $GLOBALS['awpt_test_attachment_urls'][88] = 'https://example.test/uploads/teferi.png';
    $GLOBALS['awpt_test_attachment_mime_types'][88] = 'image/png';

    $result = new PreparePatternDraft()->execute([
        'intent' => 'Build a bespoke Commander page from scratch using the Teferi image.',
        'post_type' => 'page',
        'media_count' => 1,
    ]);

    Assert::same('custom_fallback', $result['mode'] ?? '', 'explicit custom work should keep the raw composition path');
    Assert::same(88, $result['media'][0]['id'] ?? 0, 'custom composition should receive Media Library candidates');
    Assert::same(
        'https://example.test/uploads/teferi.png',
        $result['media'][0]['media_url'] ?? '',
        'custom composition should receive canonical attachment URLs',
    );
}

test_pattern_draft_extracts_general_structural_requirements_from_specific_prompt();
test_pattern_draft_does_not_invent_structural_facets_for_vague_polish_prompt();
test_compact_pattern_schema_supports_unbounded_creation_and_in_place_revision();
test_custom_pattern_fallback_preserves_requested_media_inventory();

function test_pattern_draft_does_not_treat_template_layout_as_page_content_root(): void {
    $ability = new PreparePatternDraft();
    $method = new ReflectionMethod(PreparePatternDraft::class, 'first_full_document_pattern');
    $selected = $method->invoke($ability, [
        [
            'pattern' => [
                'name' => 'theme/template-layout-page',
                'compatibility' => 'compatible',
                'composition_scope' => 'layout',
                'domain' => ['role' => 'template-layout'],
            ],
        ],
        [
            'pattern' => [
                'name' => 'theme/layout-page-basic',
                'compatibility' => 'compatible',
                'composition_scope' => 'layout',
                'domain' => ['role' => 'page-layout'],
            ],
        ],
    ]);

    Assert::same(
        'theme/layout-page-basic',
        $selected['pattern']['name'] ?? '',
        'domain role must keep template wrappers out of page-content preparation',
    );
}

function test_pattern_editable_slots_include_empty_authoring_paragraphs(): void {
    $slots = new PatternEditableSlots()->from_content('<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->');

    Assert::same('0', $slots[0]['block_path'] ?? '', 'an empty starter paragraph is still an editable path');
    Assert::same('', $slots[0]['current_text'] ?? null, 'empty slot content remains explicit');
}

test_pattern_draft_does_not_treat_template_layout_as_page_content_root();
test_pattern_editable_slots_include_empty_authoring_paragraphs();
