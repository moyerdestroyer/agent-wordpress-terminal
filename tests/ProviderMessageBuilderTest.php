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
        str_contains($system, 'awpt/propose-new-post'),
        'composition turns should include propose-new-post policy',
    );
    Assert::true(
        str_contains($system, 'pattern_mode'),
        'composition turns should include pattern composition guidance',
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
