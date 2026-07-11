<?php

/**
 * Builds OpenAI-compatible tool definitions from discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Assembles the provider `tools` array for chat completions.
 */
final class ProviderToolsBuilder {
    private ToolDiscovery $discovery;

    private ToolNameMapper $names;

    public function __construct(?ToolDiscovery $discovery = null, ?ToolNameMapper $names = null) {
        $this->discovery = $discovery ?? new ToolDiscovery();
        $this->names = $names ?? new ToolNameMapper();
    }

    /**
     * @param callable(string): bool $can_auto_execute
     * @return array<int, array<string, mixed>>
     */
    public function build(callable $can_auto_execute): array {
        $tools = [];
        $seen_functions = [];
        $catalog = array_merge($this->discovery->ability_tools(), $this->discovery->mcp_only_tools());

        foreach ($catalog as $tool) {
            $function_name = $this->names->to_function_name($tool['name']);

            if ('' === $function_name || array_key_exists($function_name, $seen_functions)) {
                continue;
            }

            if (!$can_auto_execute($tool['name'])) {
                continue;
            }

            $seen_functions[$function_name] = true;
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $function_name,
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ];
        }

        return $tools;
    }
}
