<?php

/**
 * Tests for AWPT\Agent\ToolRegistry and related helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ToolNameMapper;
use AWPT\Agent\ToolRegistry;
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

test_tool_registry_proposal_abilities();
test_tool_name_mapper_roundtrip();
test_tool_preferences_deny_list();
test_tool_registry_respects_never_auto();
