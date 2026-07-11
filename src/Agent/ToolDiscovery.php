<?php

/**
 * Discovers WordPress abilities and MCP-only tools on the site.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Enumerates tools any plugin/theme/MCP integration registered.
 */
final class ToolDiscovery {
    private AbilityToolDiscovery $abilities;

    private McpOnlyToolDiscovery $mcp;

    public function __construct(?AbilityToolDiscovery $abilities = null, ?McpOnlyToolDiscovery $mcp = null) {
        $this->abilities = $abilities ?? new AbilityToolDiscovery();
        $this->mcp = $mcp ?? new McpOnlyToolDiscovery();
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function ability_tools(): array {
        return $this->abilities->tools();
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function mcp_only_tools(): array {
        $ability_names = [];

        foreach ($this->ability_tools() as $tool) {
            $ability_names[$tool['name']] = true;
        }

        return $this->mcp->tools($ability_names);
    }

    /**
     * @return list<string>
     */
    public function all_names(): array {
        $names = [];

        foreach ($this->ability_tools() as $tool) {
            $names[] = $tool['name'];
        }

        foreach ($this->mcp_only_tools() as $tool) {
            $names[] = $tool['name'];
        }

        return $names;
    }

    public function is_ability(string $tool_name): bool {
        return $this->abilities->is_ability($tool_name);
    }
}
