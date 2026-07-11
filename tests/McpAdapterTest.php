<?php

/**
 * Tests for AWPT\MCP\Adapter execution policy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\MCP\Adapter;

function test_mcp_adapter_allows_destructive_and_approval_annotated_tools(): void {
    awpt_test_reset_state();

    add_filter('awpt_mcp_tools', static function (mixed $tools): array {
        unset($tools);

        return [
            [
                'name' => 'demo/write-thing',
                'description' => 'Would run under MCP with the current user.',
                'destructive' => true,
                'requires_approval' => true,
                'readonly' => false,
            ],
        ];
    });

    add_filter(
        'awpt_mcp_execute_tool',
        static function (mixed $result, string $tool_name, array $input, array $tool): array {
            unset($result, $tool);

            return [
                'ok' => true,
                'tool' => $tool_name,
                'input' => $input,
            ];
        },
        10,
        4,
    );

    $adapter = new Adapter();
    $result = $adapter->execute_tool('demo/write-thing', ['title' => 'Hello']);

    Assert::false(is_wp_error($result), 'MCP adapter must not re-stage tools MCP would run directly');
    Assert::true(is_array($result), 'successful MCP execution returns an array payload');
    Assert::same('demo/write-thing', $result['tool'] ?? null, 'executed tool name is preserved');
    Assert::same('Hello', $result['input']['title'] ?? null, 'tool input is forwarded');
}

test_mcp_adapter_allows_destructive_and_approval_annotated_tools();
