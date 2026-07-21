<?php

/**
 * Discovers WordPress Abilities for the agent tool loop.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\AbilitySchemas;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists registered abilities as provider-ready tool rows.
 */
final class AbilityToolDiscovery {
    /**
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>, annotations: array<string, bool|null>}>
     */
    public function tools(): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $tools = [];

        foreach (wp_get_abilities() as $ability) {
            $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';

            if ('' === $name) {
                continue;
            }

            $raw_schema = method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null;
            $schema = is_array($raw_schema) ? $this->string_keyed($raw_schema) : AbilitySchemas::empty_object_input();
            $description = method_exists($ability, 'get_description') ? (string) $ability->get_description() : $name;
            $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : [];
            $raw_annotations = is_array($meta) && is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];

            $tools[] = [
                'name' => $name,
                'description' => $description,
                'parameters' => AbilitySchemas::normalize_for_provider($schema),
                'annotations' => [
                    'readonly' => array_key_exists('readonly', $raw_annotations)
                        ? (bool) $raw_annotations['readonly']
                        : null,
                    'destructive' => array_key_exists('destructive', $raw_annotations)
                        ? (bool) $raw_annotations['destructive']
                        : null,
                    'requires_approval' => array_key_exists('requires_approval', $raw_annotations)
                        ? (bool) $raw_annotations['requires_approval']
                        : null,
                ],
            ];
        }

        return $tools;
    }

    public function is_ability(string $tool_name): bool {
        if (!function_exists('wp_get_ability')) {
            return false;
        }

        return null !== wp_get_ability($tool_name);
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
