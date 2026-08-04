<?php

/**
 * Tests for AWPT\Agent\ToolRegistry and related helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ToolNameMapper;
use AWPT\Agent\ToolRegistry;
use AWPT\Agent\TurnProfile;
use AWPT\Support\ToolPreferences;

function test_tool_registry_proposal_abilities(): void {
    $names = ToolRegistry::proposal_ability_names();

    Assert::true(
        in_array('awpt/propose-new-post', $names, true),
        'new-post proposals must surface as staged action cards',
    );
    Assert::true(
        ToolRegistry::is_proposal_ability('awpt/propose-new-post'),
        'is_proposal_ability should recognize awpt/propose-new-post',
    );
    Assert::true(
        ToolRegistry::is_proposal_ability('awpt/propose-navigation-change'),
        'semantic navigation changes must surface as staged action cards',
    );
    Assert::true(
        ToolRegistry::is_proposal_ability('awpt/propose-global-styles-patch'),
        'partial Global Styles changes must surface as staged action cards',
    );
    Assert::false(
        ToolRegistry::is_proposal_ability('awpt/sideload-media'),
        'non-proposal tools should not be treated as staged actions',
    );
}

function test_tool_name_mapper_roundtrip(): void {
    $mapper = new ToolNameMapper();

    Assert::same(
        'core__get_site_info',
        $mapper->to_function_name('core/get-site-info'),
        'ability names map to OpenAI-safe function names',
    );
    Assert::same(
        'core/get-site-info',
        $mapper->to_tool_name('core__get_site_info'),
        'function names map back to ability names',
    );
    Assert::same(
        'ai__get_post_details',
        $mapper->to_function_name('ai/get-post-details'),
        'third-party ability namespaces map correctly',
    );
    Assert::same(
        'ai/get-post-details',
        $mapper->to_tool_name('ai__get_post_details'),
        'third-party function names reverse correctly',
    );
    Assert::same(
        'core__posts__find',
        $mapper->to_function_name('core/posts/find'),
        'nested ability names map every slash-delimited segment',
    );
    Assert::same(
        'core/posts/find',
        $mapper->to_tool_name('core__posts__find'),
        'nested function names map back to every ability segment',
    );
    Assert::same(
        'my-plugin/resource/sub/action',
        $mapper->to_tool_name('my_plugin__resource__sub__action'),
        'four-segment ability names reverse correctly',
    );
    Assert::same(
        'awpt/propose-new-post',
        $mapper->from_wordpress_ability_function_name('wpab__awpt__propose-new-post'),
        'WordPress AI Client transport names must recover the canonical proposal ability',
    );
}

function test_tool_preferences_deny_list(): void {
    awpt_test_reset_state();
    $prefs = new ToolPreferences();

    Assert::true($prefs->is_enabled('ai/get-post-details'), 'tools are enabled by default');
    Assert::true($prefs->is_never_auto('awpt/apply-action'), 'apply-action is human-only');

    $disabled = $prefs->disable_tool('ai/get-post-details');
    Assert::true(in_array('ai/get-post-details', $disabled, true), 'disabled tools are stored');
    Assert::false($prefs->is_enabled('ai/get-post-details'), 'disabled tools report as disabled');

    $prefs->enable_tool('ai/get-post-details');
    Assert::true($prefs->is_enabled('ai/get-post-details'), 're-enabled tools report as enabled');
}

function test_tool_registry_respects_never_auto(): void {
    $registry = new ToolRegistry();

    Assert::false($registry->can_auto_execute('awpt/apply-action'), 'apply-action must never be model-auto-executable');
}

function test_tool_registry_uses_annotations_and_explicit_mutation_trust(): void {
    awpt_test_reset_state();
    add_filter('awpt_mcp_tools', static fn(): array => [
        ['name' => 'demo/read', 'description' => 'Read', 'readonly' => true, 'destructive' => false],
        ['name' => 'demo/write', 'description' => 'Write', 'readonly' => false, 'destructive' => true],
    ]);
    $prefs = new ToolPreferences();
    $registry = new ToolRegistry($prefs);

    Assert::true($registry->can_auto_execute('demo/read'), 'declared read-only tools should be automatic');
    Assert::false($registry->can_auto_execute('demo/write'), 'direct mutations should require explicit trust');

    $prefs->set_mutating_trust('demo/write', true);
    Assert::true(
        new ToolRegistry($prefs)->can_auto_execute('demo/write'),
        'an admin should be able to explicitly trust a mutating tool',
    );
}

function test_turn_profiles_do_not_append_unrelated_discovered_tools(): void {
    awpt_test_reset_state();
    wp_register_ability('awpt/find-abilities', [
        'description' => 'Find abilities',
        'input_schema' => ['type' => 'object'],
        'meta' => ['annotations' => ['readonly' => true]],
    ]);
    wp_register_ability('demo/unrelated-read', [
        'description' => 'Unrelated third-party read',
        'input_schema' => ['type' => 'object'],
        'meta' => ['annotations' => ['readonly' => true]],
    ]);
    $profile = TurnProfile::from_message('Hello there');
    $tools = new ToolRegistry()->get_chat_completion_tools_for_profile($profile);
    $names = array_map(static fn(array $tool): string => (string) ($tool['function']['name'] ?? ''), $tools);

    Assert::true(in_array('awpt__find_abilities', $names, true), 'ability search should be offered on every turn');
    Assert::false(
        in_array('demo__unrelated_read', $names, true),
        'contextual profiles should not append every discovered read tool',
    );
}

test_tool_registry_proposal_abilities();
test_tool_name_mapper_roundtrip();
test_tool_preferences_deny_list();
test_tool_registry_respects_never_auto();
test_tool_registry_uses_annotations_and_explicit_mutation_trust();
test_turn_profiles_do_not_append_unrelated_discovered_tools();
