<?php

/**
 * Tests verified Core ability replacement policy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\AbilityReplacementRegistry;
use AWPT\Support\ToolPreferences;

function register_compatible_core_read_content(): void {
    wp_register_ability('core/read-content', [
        'input_schema' => [
            'type' => 'object',
            'oneOf' => [[
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'fields' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => ['id', 'content_raw']],
                    ],
                ],
            ]],
        ],
        'output_schema' => [
            'type' => 'object',
            'oneOf' => [[
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'content_raw' => ['type' => 'string'],
                ],
            ]],
        ],
    ]);
}

function test_replacement_requires_a_verified_schema_contract(): void {
    awpt_test_reset_state();
    wp_register_ability('core/read-content', [
        'input_schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        'output_schema' => ['type' => 'object'],
    ]);

    Assert::same(
        'awpt/read-content',
        new AbilityReplacementRegistry()->preferred('awpt/read-content'),
        'an incomplete Core schema must keep the AWPT fallback',
    );

    register_compatible_core_read_content();
    Assert::same(
        'core/read-content',
        new AbilityReplacementRegistry()->preferred('awpt/read-content'),
        'a compatible live Core schema should replace the AWPT ability',
    );
}

function test_replacement_aliases_share_tool_preferences(): void {
    awpt_test_reset_state();
    register_compatible_core_read_content();
    $preferences = new ToolPreferences();
    $disabled = $preferences->disable_tool('core/read-content');

    Assert::true(in_array('awpt/read-content', $disabled, true), 'fallback alias should be disabled too');
    Assert::false($preferences->is_enabled('core/read-content'), 'Core replacement should report disabled');
    Assert::false($preferences->is_enabled('awpt/read-content'), 'fallback should report the same preference');

    $preferences->enable_tool('awpt/read-content');
    Assert::true($preferences->is_enabled('core/read-content'), 'enabling either alias should enable both');
}

test_replacement_requires_a_verified_schema_contract();
test_replacement_aliases_share_tool_preferences();
