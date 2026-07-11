<?php

/**
 * Tool discovery slash command.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\MCP\Adapter;
use AWPT\Support\ToolPreferences;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles slash commands for tool discovery.
 */
final class ToolCommandRouter {
    /**
     * Handle /tools command.
     *
     * @return array<string, mixed>
     */
    public function tools(): array {
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
    private function groups(): array {
        $prefs = new ToolPreferences();
        $core_label = __('Core Abilities', 'agent-wordpress-terminal');
        $awpt_label = __('AWPT Abilities', 'agent-wordpress-terminal');
        $other_label = __('Other plugin/theme abilities', 'agent-wordpress-terminal');
        $mcp_label = __('MCP Tools', 'agent-wordpress-terminal');
        $groups = [
            $core_label => [],
            $awpt_label => [],
            $other_label => [],
            $mcp_label => [],
        ];

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $name = $ability->get_name();
                $suffix = $this->status_suffix($name, $prefs);

                if (str_starts_with($name, 'core/')) {
                    $groups[$core_label][] = $name . $suffix;
                    continue;
                }

                if (str_starts_with($name, 'awpt/')) {
                    $groups[$awpt_label][] = $name . $suffix;
                    continue;
                }

                $groups[$other_label][] = $name . $suffix;
            }
        }

        $seen = [];

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $seen[$ability->get_name()] = true;
            }
        }

        foreach (new Adapter()->list_tools() as $tool) {
            $name = (string) ($tool['name'] ?? '');

            if ('' === $name || array_key_exists($name, $seen)) {
                continue;
            }

            $groups[$mcp_label][] = $name . $this->status_suffix($name, $prefs);
        }

        return $groups;
    }

    private function status_suffix(string $name, ToolPreferences $prefs): string {
        if ($prefs->is_never_auto($name)) {
            return ' ' . __('(human-only)', 'agent-wordpress-terminal');
        }

        if (!$prefs->is_enabled($name)) {
            return ' ' . __('(disabled)', 'agent-wordpress-terminal');
        }

        return '';
    }

    /**
     * Format grouped tool names for terminal output.
     *
     * @param array<string, array<int, string>> $groups Grouped tool names.
     */
    private function format_groups(array $groups): string {
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
