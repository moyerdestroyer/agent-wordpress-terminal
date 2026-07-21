<?php

/**
 * Discovers MCP tools that are not also WordPress abilities.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\AbilitySchemas;
use AWPT\MCP\Adapter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists MCP-only tools for the agent tool loop.
 */
final class McpOnlyToolDiscovery {
    /**
     * @param array<string, true> $ability_names Ability names already discovered.
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>, annotations: array<string, bool|null>}>
     */
    public function tools(array $ability_names): array {
        $tools = [];

        foreach (new Adapter()->list_tools() as $tool) {
            $name = (string) ($tool['name'] ?? '');

            if ('' === $name || array_key_exists($name, $ability_names)) {
                continue;
            }

            $raw_schema = $tool['input_schema'] ?? null;
            $schema = is_array($raw_schema) ? $this->string_keyed($raw_schema) : AbilitySchemas::empty_object_input();

            $tools[] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? $name),
                'parameters' => AbilitySchemas::normalize_for_provider($schema),
                'annotations' => [
                    'readonly' => array_key_exists('readonly', $tool) ? (bool) $tool['readonly'] : null,
                    'destructive' => array_key_exists('destructive', $tool) ? (bool) $tool['destructive'] : null,
                    'requires_approval' => array_key_exists('requires_approval', $tool)
                        ? (bool) $tool['requires_approval']
                        : null,
                ],
            ];
        }

        return $tools;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array<string, mixed>
     */
    private function string_keyed(array $schema): array {
        $out = [];

        foreach ($schema as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
