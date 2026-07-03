<?php

/**
 * Tool discovery slash command.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\MCP\Adapter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles slash commands for tool discovery.
 */
final class ToolCommandRouter
{
    /**
     * Handle /tools command.
     *
     * @return array<string, mixed>
     */
    public function tools(): array
    {
        return [
            'content' => $this->format_groups($this->groups()),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'tools',
        ];
    }

    /**
     * Build grouped tool names.
     *
     * @return array<string, array<int, string>>
     */
    private function groups(): array
    {
        $core_label = __('Core Abilities', 'agent-wordpress-terminal');
        $plugin_label = __('Plugin Abilities', 'agent-wordpress-terminal');
        $mcp_label = __('MCP Tools', 'agent-wordpress-terminal');
        $groups = [
            $core_label => [],
            $plugin_label => [],
            $mcp_label => [],
        ];

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $name = $ability->get_name();

                if (str_starts_with($name, 'core/')) {
                    $groups[$core_label][] = $name;
                    continue;
                }

                if (str_starts_with($name, 'awpt/')) {
                    $groups[$plugin_label][] = $name;
                }
            }
        }

        foreach (new Adapter()->list_tools() as $tool) {
            $groups[$mcp_label][] = (string) $tool['name'];
        }

        return $groups;
    }

    /**
     * Format grouped tool names for terminal output.
     *
     * @param array<string, array<int, string>> $groups Grouped tool names.
     */
    private function format_groups(array $groups): string
    {
        $lines = [];

        foreach ($groups as $label => $names) {
            $lines[] = $label . ':';
            $lines[] = [] === $names
                ? '- ' . __('None discovered', 'agent-wordpress-terminal')
                : implode("\n", array_map(static fn(string $name): string => '- ' . $name, $names));
        }

        return implode("\n", $lines);
    }
}
