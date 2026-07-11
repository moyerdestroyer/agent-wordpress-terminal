<?php

/**
 * Tests for WordPress MCP bridge helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\MCP\WordPressMcpAbilityCatalog;
use AWPT\MCP\WordPressMcpBridge;
use AWPT\MCP\WordPressMcpMeta;

function test_wordpress_mcp_bridge_meta_helpers(): void {
    Assert::false(WordPressMcpMeta::is_public([]), 'empty meta is not MCP-public');
    Assert::false(WordPressMcpMeta::is_public(['mcp' => ['public' => false]]), 'explicit false is not MCP-public');
    Assert::false(WordPressMcpMeta::is_public(['mcp' => 'yes']), 'non-array mcp meta is not MCP-public');
    Assert::true(WordPressMcpMeta::is_public(['mcp' => ['public' => true]]), 'meta.mcp.public true is MCP-public');

    $annotations = WordPressMcpMeta::annotations([
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'requires_approval' => true,
        ],
    ]);
    Assert::true(true === $annotations['readonly'], 'readonly annotation is preserved');
    Assert::true(false === $annotations['destructive'], 'destructive annotation is preserved');
    Assert::true(true === $annotations['requires_approval'], 'requires_approval annotation is preserved');

    $empty = WordPressMcpMeta::annotations([]);
    Assert::true(null === $empty['readonly'], 'missing annotations stay null');
    Assert::true(null === $empty['destructive'], 'missing destructive stays null');
    Assert::true(null === $empty['requires_approval'], 'missing requires_approval stays null');
}

function test_wordpress_mcp_bridge_unavailable_without_adapter(): void {
    $bridge = new WordPressMcpBridge();

    Assert::false($bridge->is_available(), 'without MCP adapter classes or REST routes, bridge is unavailable');
    Assert::false($bridge->filter_connected(false), 'connected filter stays false when unavailable');
    Assert::true($bridge->filter_connected(true), 'connected filter respects an already-true upstream value');
    Assert::same('', $bridge->filter_server_url(''), 'server URL stays empty when unavailable');
    Assert::same(
        'https://example.test/mcp',
        $bridge->filter_server_url('https://example.test/mcp'),
        'server URL filter preserves an upstream URL',
    );
    Assert::same([], $bridge->filter_tools([]), 'tools filter returns empty list when unavailable');
    Assert::true(
        null === $bridge->filter_execute(null, 'core/get-site-info', [], ['name' => 'core/get-site-info']),
        'execute filter does not handle tools when unavailable',
    );
}

function test_wordpress_mcp_catalog_merge_dedupes_by_name(): void {
    $list = new AWPT\MCP\WordPressMcpToolList();
    $merged = $list->merge([
        ['name' => 'external/tool', 'description' => 'from another integration'],
        ['name' => 'external/tool', 'description' => 'duplicate should drop'],
        'not-an-array',
        ['description' => 'missing name'],
    ], [
        ['name' => 'catalog/tool', 'description' => 'from catalog'],
        ['name' => 'external/tool', 'description' => 'catalog duplicate ignored'],
    ]);

    Assert::same(2, count($merged), 'merge keeps one entry per tool name');
    Assert::same('external/tool', $merged[0]['name'], 'external tool name is preserved');
    Assert::same('catalog/tool', $merged[1]['name'], 'catalog tool is appended once');
}

test_wordpress_mcp_bridge_meta_helpers();
test_wordpress_mcp_bridge_unavailable_without_adapter();
test_wordpress_mcp_catalog_merge_dedupes_by_name();
