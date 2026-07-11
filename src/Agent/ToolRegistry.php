<?php

/**
 * Agent tool registry — discovers site-wide abilities and MCP tools.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ProposalAbilities;
use AWPT\Support\ToolPreferences;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds provider tools from every registered WordPress ability and connected MCP tool.
 *
 * Plugins/themes register Abilities (or MCP integrations hook `awpt_mcp_tools`);
 * AWPT offers them to the agent unless the admin disables them. A small deny-list
 * (`awpt/apply-action`) stays human-only.
 */
final class ToolRegistry {
    private ToolPreferences $preferences;

    private ToolNameMapper $names;

    private ToolDiscovery $discovery;

    /**
     * @var array<string, true>|null
     */
    private ?array $discovered_set = null;

    public function __construct(
        ?ToolPreferences $preferences = null,
        ?ToolNameMapper $names = null,
        ?ToolDiscovery $discovery = null,
    ) {
        $this->preferences = $preferences ?? new ToolPreferences();
        $this->names = $names ?? new ToolNameMapper();
        $this->discovery = $discovery ?? new ToolDiscovery();
    }

    /**
     * @return list<string>
     */
    public static function proposal_ability_names(): array {
        return ProposalAbilities::names();
    }

    public static function is_proposal_ability(string $tool_name): bool {
        return ProposalAbilities::is_proposal($tool_name);
    }

    /**
     * @return list<string>
     */
    public function get_auto_executable_ability_names(): array {
        $names = [];

        foreach ($this->discovered_tool_names() as $tool_name) {
            if (!$this->can_auto_execute($tool_name)) {
                continue;
            }

            $names[] = $tool_name;
        }

        return $names;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_chat_completion_tools(): array {
        return new ProviderToolsBuilder($this->discovery, $this->names)->build([$this, 'can_auto_execute']);
    }

    public function function_name_for_ability(string $ability_name): ?string {
        if ('' === $ability_name || !$this->is_discovered($ability_name)) {
            return null;
        }

        $function_name = $this->names->to_function_name($ability_name);

        return '' !== $function_name ? $function_name : null;
    }

    public function tool_name_for_function(string $function_name): ?string {
        if (class_exists('WP_AI_Client_Ability_Function_Resolver') && str_starts_with($function_name, 'wpab__')) {
            $ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name($function_name);

            return $this->can_auto_execute($ability_name) ? $ability_name : null;
        }

        $tool_name = $this->names->to_tool_name($function_name);

        if ('' === $tool_name || !$this->is_discovered($tool_name)) {
            return null;
        }

        return $tool_name;
    }

    public function can_auto_execute(string $tool_name): bool {
        if ('' === $tool_name || $this->preferences->is_never_auto($tool_name)) {
            return false;
        }

        if (!$this->preferences->is_enabled($tool_name)) {
            return false;
        }

        return $this->is_discovered($tool_name);
    }

    public function preferred_site_info_ability(): ?string {
        foreach (['core/read-settings', 'core/get-site-info'] as $ability_name) {
            if ($this->can_auto_execute($ability_name)) {
                return $ability_name;
            }
        }

        return null;
    }

    public function is_ability(string $tool_name): bool {
        return $this->discovery->is_ability($tool_name);
    }

    /**
     * @return list<string>
     */
    public function discovered_tool_names(): array {
        return $this->discovery->all_names();
    }

    private function is_discovered(string $tool_name): bool {
        return array_key_exists($tool_name, $this->discovered_index());
    }

    /**
     * @return array<string, true>
     */
    /**
     * @return array<string, true>
     */
    private function discovered_index(): array {
        if (null === $this->discovered_set) {
            $index = [];

            foreach ($this->discovered_tool_names() as $name) {
                $index[$name] = true;
            }

            $this->discovered_set = $index;
        }

        return $this->discovered_set;
    }
}
