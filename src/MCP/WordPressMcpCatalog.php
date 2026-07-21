<?php

/**
 * MCP ability catalog and in-process execution.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

use AWPT\Agent\ToolExecutor;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Discovers MCP-facing abilities and runs them via ToolExecutor.
 */
final class WordPressMcpCatalog {
    /**
     * @var list<string>
     */
    private const ADAPTER_ABILITY_PREFIXES = [
        'mcp-adapter/',
        'mcp/',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_tools(): array {
        return $this->list_tools_from_abilities();
    }

    /**
     * @param array<array-key, mixed>          $existing
     * @param array<int, array<string, mixed>> $catalog
     * @return array<int, array<string, mixed>>
     */
    public function merge_tools(array $existing, array $catalog): array {
        $merged = $this->normalize_tools($existing);
        $seen = [];

        foreach ($merged as $tool) {
            $name = $tool['name'] ?? null;
            if (is_string($name) && '' !== $name) {
                $seen[$name] = true;
            }
        }

        foreach ($catalog as $tool) {
            $name = $tool['name'] ?? null;
            if (!is_string($name) || '' === $name || array_key_exists($name, $seen)) {
                continue;
            }
            $merged[] = $tool;
            $seen[$name] = true;
        }

        return $merged;
    }

    /**
     * @param string                  $tool_name
     * @param array<array-key, mixed> $input
     * @param array<string, mixed>    $tool
     * @return array<array-key, mixed>|\WP_Error|null
     */
    public function execute(string $tool_name, array $input, array $tool): array|\WP_Error|null {
        return $this->execute_ability($tool_name, $input, $tool);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_tools_from_abilities(): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        /** @var list<array<string, mixed>> $tools */
        $tools = [];

        foreach (wp_get_abilities() as $ability) {
            if (!$this->should_expose_ability($ability)) {
                continue;
            }

            $item = $this->tool_from_ability($ability);

            if (null !== $item) {
                $tools[] = $item;
            }
        }

        return $tools;
    }

    /**
     * @param object $ability Ability instance.
     */
    private function should_expose_ability(object $ability): bool {
        $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';

        if ('' === $name) {
            return false;
        }

        if ($this->is_adapter_namespace($name)) {
            return true;
        }

        return WordPressMcpBridge::is_public($this->ability_meta($ability));
    }

    /**
     * @param object $ability Ability instance.
     * @return array<string, mixed>|null
     */
    private function tool_from_ability(object $ability): ?array {
        $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';

        if ('' === $name) {
            return null;
        }

        $meta = $this->ability_meta($ability);
        $annotations = WordPressMcpBridge::annotations($meta);
        $label = method_exists($ability, 'get_label') ? (string) $ability->get_label() : $name;
        $description = method_exists($ability, 'get_description') ? (string) $ability->get_description() : '';
        $category = method_exists($ability, 'get_category') ? (string) $ability->get_category() : 'mcp';
        $input_schema = method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null;
        $output_schema = method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null;

        return [
            'name' => $name,
            'label' => '' !== $label ? $label : $name,
            'description' => $description,
            'category' => '' !== $category ? $category : 'mcp',
            'input_schema' => is_array($input_schema)
                ? $this->string_keyed($input_schema)
                : ['type' => 'object', 'properties' => []],
            'output_schema' => is_array($output_schema) ? $this->string_keyed($output_schema) : null,
            'permission' => 'capability check',
            'readonly' => $annotations['readonly'],
            'destructive' => $annotations['destructive'],
            'requires_approval' => false,
            'ability' => $name,
        ];
    }

    /**
     * @param string                  $tool_name Tool / ability name.
     * @param array<array-key, mixed> $input Tool input.
     * @param array<string, mixed>    $tool Normalized tool metadata.
     * @return array<array-key, mixed>|\WP_Error|null
     */
    private function execute_ability(string $tool_name, array $input, array $tool): array|\WP_Error|null {
        $ability_name = $this->resolve_ability_name($tool_name, $tool);

        if (null === $ability_name || !$this->is_mcp_facing_ability_name($ability_name)) {
            return null;
        }

        return new ToolExecutor()->execute($ability_name, $this->string_keyed($input));
    }

    private function is_adapter_namespace(string $ability_name): bool {
        foreach (self::ADAPTER_ABILITY_PREFIXES as $prefix) {
            if (str_starts_with($ability_name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tool Tool metadata.
     */
    private function resolve_ability_name(string $tool_name, array $tool): ?string {
        $from_meta = $tool['ability'] ?? null;
        $ability_from_meta = is_string($from_meta) ? $from_meta : null;

        if (null !== $ability_from_meta && '' !== $ability_from_meta) {
            return $ability_from_meta;
        }

        if ('' === $tool_name) {
            return null;
        }

        if (function_exists('wp_get_ability') && null !== wp_get_ability($tool_name)) {
            return $tool_name;
        }

        if (str_contains($tool_name, '-') && !str_contains($tool_name, '/')) {
            $slash_name = preg_replace('/-/', '/', $tool_name, 1);

            if (is_string($slash_name) && function_exists('wp_get_ability') && null !== wp_get_ability($slash_name)) {
                return $slash_name;
            }
        }

        return $tool_name;
    }

    private function is_mcp_facing_ability_name(string $ability_name): bool {
        if ($this->is_adapter_namespace($ability_name)) {
            return true;
        }

        if (!function_exists('wp_get_ability')) {
            return false;
        }

        $ability = wp_get_ability($ability_name);

        return is_object($ability) && WordPressMcpBridge::is_public($this->ability_meta($ability));
    }

    /**
     * @param array<array-key, mixed> $existing Existing tool definitions.
     * @return array<int, array<string, mixed>>
     */
    public function normalize_tools(array $existing): array {
        $merged = [];
        $seen = [];

        foreach ($existing as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            $row = $this->string_keyed($tool);
            $name = $row['name'] ?? null;

            if (!is_string($name) || '' === $name || array_key_exists($name, $seen)) {
                continue;
            }

            $merged[] = $row;
            $seen[$name] = true;
        }

        return $merged;
    }

    /**
     * @param object $ability Ability instance.
     * @return array<array-key, mixed>
     */
    private function ability_meta(object $ability): array {
        if (!method_exists($ability, 'get_meta')) {
            return [];
        }

        $meta = $ability->get_meta();

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<array-key, mixed> $value Raw map.
     * @return array<string, mixed>
     */
    private function string_keyed(array $value): array {
        $out = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $out[$key] = $item;
        }

        return $out;
    }
}
