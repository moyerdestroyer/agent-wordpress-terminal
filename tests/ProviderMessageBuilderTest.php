<?php

/**
 * Proportional system prompts by turn profile.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ProviderMessageBuilder;
use AWPT\Agent\ToolCatalogFormatter;
use AWPT\Agent\TurnProfile;

function test_tool_catalog_is_short_and_does_not_list_every_ability(): void {
    $catalog = new ToolCatalogFormatter()->get_system_prompt_catalog();

    Assert::true(strlen($catalog) < 1_200, 'system tool note should stay compact');
    Assert::false(
        str_contains($catalog, 'Auto-callable tools currently enabled'),
        'full ability dump should no longer appear in the system prompt',
    );
    Assert::true(str_contains($catalog, 'function tools'), 'catalog should point at structured tools[] declarations');
}

function test_chat_prompt_omits_compose_and_design_tokens(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $profile = TurnProfile::from_message('Hello there');
    $messages = new ProviderMessageBuilder()->build(
        1,
        [
            'query' => 'Hello there',
            'query_fingerprint' => 'x',
            'items' => [],
            'known_matches' => [],
            'novel_count' => 0,
            'reused_count' => 0,
            'exhausted' => false,
            'skipped' => true,
        ],
        $profile,
    );

    $system = (string) ($messages[0]['content'] ?? '');

    Assert::true(str_contains($system, 'You are AWPT'), 'core identity should remain');
    Assert::false(
        str_contains($system, 'propose-new-post in the same turn with the complete revised title'),
        'compose revision policy should be omitted on pure chat',
    );
    Assert::false(
        str_contains($system, 'Resolved WordPress design tokens:'),
        'design tokens should be omitted on pure chat',
    );
    Assert::true(strlen($system) < 8_000, 'chat system prompt should be far smaller than the previous always-on wall');
}

function test_compose_prompt_includes_composition_policy(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $message = 'Create a polished landing page for a neighborhood garden club.';
    $profile = TurnProfile::from_message($message);
    $messages = new ProviderMessageBuilder()->build(2, null, $profile);
    $system = (string) ($messages[0]['content'] ?? '');

    Assert::true(
        str_contains($system, 'awpt/prepare-pattern-draft'),
        'composition turns should include compact pattern preparation policy',
    );
    Assert::true(
        str_contains($system, 'awpt/propose-patterned-post'),
        'composition turns should include pattern composition guidance',
    );
    Assert::true(
        str_contains($system, 'awpt/propose-new-post'),
        'composition turns should retain the unrestricted custom fallback',
    );
    Assert::true($profile->include_design_tokens(), 'composition turns should request design tokens');
}

test_tool_catalog_is_short_and_does_not_list_every_ability();
test_chat_prompt_omits_compose_and_design_tokens();
test_compose_prompt_includes_composition_policy();

function test_edit_prompt_prefers_page_content_over_template_writes(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $profile = TurnProfile::from_message('Hey, can you clean up page 410?');
    $messages = new ProviderMessageBuilder()->build(3, null, $profile);
    $system = (string) ($messages[0]['content'] ?? '');

    Assert::true($profile->needs_edit_module(), 'cleanup should load the edit policy module');
    Assert::true(
        str_contains($system, 'do not propose template or global-styles updates solely to clean up'),
        'edit policy should soft-discourage template writes for page-local cleanup',
    );
    Assert::true(
        str_contains($system, 'do not stop after discovery alone'),
        'edit policy should nudge corrections to stage in the same turn',
    );
}

test_edit_prompt_prefers_page_content_over_template_writes();

function test_presentation_edit_prompt_requires_structural_and_visual_judgment(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $profile = TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]);
    $messages = new ProviderMessageBuilder()->build(4, null, $profile);
    $system = (string) ($messages[0]['content'] ?? '');

    Assert::true(
        str_contains($system, 'inspect the complete current page with awpt/analyze-page'),
        'presentation edits should require complete structural inspection',
    );
    Assert::true(
        str_contains($system, 'awpt/inspect-rendered-element'),
        'presentation edits should require rendered inspection',
    );
    Assert::true(
        str_contains($system, 'Decide the appropriate scope yourself'),
        'AWPT should own routine transformation scope in every surface',
    );
    Assert::true(
        str_contains($system, 'substantial full-page layout adaptation'),
        'generic presentation work should permit a large page overhaul when inspection warrants one',
    );
    Assert::true(
        str_contains($system, 'Do not assume the active template displays post_title'),
        'rendered evidence should determine whether an existing page needs a page-local H1',
    );
    Assert::true(
        str_contains($system, 'place the page H1 before its introductory prose'),
        'ordinary document improvements should not strand explanatory copy above the page title',
    );
    Assert::true(
        str_contains($system, 'awpt/propose-content-update with pattern_name provenance'),
        'generic presentation work should expose the normal active-theme pattern adaptation path',
    );
    Assert::true(
        str_contains($system, 'call awpt/recommend-patterns'),
        'recognizable page archetypes should trigger pattern discovery before the scope decision',
    );
    Assert::false(
        str_contains($system, 'Do not reorder blocks'),
        'generic presentation work should not be artificially limited to cosmetic attribute changes',
    );
    Assert::false(
        str_contains($system, 'A full-document update is a last resort'),
        'full-page adaptation should be selected on merit rather than discouraged categorically',
    );
    Assert::true(
        str_contains($system, 'must describe only changes actually present'),
        'proposal copy must not promise structural work absent from its operation payload',
    );
    Assert::false(
        str_contains($system, 'review queue'),
        'presentation guidance should not mention or depend on the hosting surface',
    );
}

test_presentation_edit_prompt_requires_structural_and_visual_judgment();
