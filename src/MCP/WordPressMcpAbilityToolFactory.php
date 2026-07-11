<?php

/**
 * Builds AWPT MCP tool definitions from WordPress abilities.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts a WP_Ability-like object into Adapter tool metadata.
 */
final class WordPressMcpAbilityToolFactory {
    /**
     * @param object $ability Ability instance.
     * @return array<string, mixed>|null
     */
    public function from_ability(object $ability): ?array {
        $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';

        if ('' === $name) {
            return null;
        }

        $meta = WordPressMcpMeta::ability_meta($ability);
        $annotations = WordPressMcpMeta::annotations($meta);
        $label = method_exists($ability, 'get_label') ? (string) $ability->get_label() : $name;
        $description = method_exists($ability, 'get_description') ? (string) $ability->get_description() : '';
        $category = method_exists($ability, 'get_category') ? (string) $ability->get_category() : 'mcp';
        $input_schema = $this->schema_or_default(
            method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null,
            ['type' => 'object', 'properties' => []],
        );
        $output_schema = $this->schema_or_null(
            method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null,
        );

        return [
            'name' => $name,
            'label' => '' !== $label ? $label : $name,
            'description' => $description,
            'category' => '' !== $category ? $category : 'mcp',
            'input_schema' => $input_schema,
            'output_schema' => $output_schema,
            // Display-only; real authz is the ability permission_callback.
            'permission' => 'capability check',
            'readonly' => $annotations['readonly'],
            // Risk signal only — MCP clients do not re-stage; ability permissions apply.
            'destructive' => $annotations['destructive'],
            // Do not advertise AWPT staged approval for tools MCP would run directly.
            'requires_approval' => false,
            'ability' => $name,
        ];
    }

    /**
     * @param mixed                    $schema Raw schema.
     * @param array<string, mixed>     $default Fallback schema.
     * @return array<string, mixed>
     */
    private function schema_or_default(mixed $schema, array $default): array {
        return is_array($schema) ? $this->string_keyed($schema) : $default;
    }

    /**
     * @param mixed $schema Raw schema.
     * @return array<string, mixed>|null
     */
    private function schema_or_null(mixed $schema): ?array {
        return is_array($schema) ? $this->string_keyed($schema) : null;
    }

    /**
     * @param array<array-key, mixed> $schema Raw schema array.
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
