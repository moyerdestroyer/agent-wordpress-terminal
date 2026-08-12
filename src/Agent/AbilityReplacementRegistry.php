<?php

/**
 * Verified replacements for AWPT abilities supplied by WordPress Core.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

final class AbilityReplacementRegistry {
    /**
     * @return array<string, string> AWPT ability => Core ability.
     */
    public function configured(): array {
        $configured = apply_filters('awpt_ability_replacements', [
            'awpt/read-content' => 'core/read-content',
        ]);

        if (!is_array($configured)) {
            return [];
        }

        $replacements = [];

        foreach ($configured as $fallback => $replacement) {
            if (!is_string($fallback) || !is_string($replacement) || '' === $fallback || '' === $replacement) {
                continue;
            }

            $replacements[$fallback] = $replacement;
        }

        return $replacements;
    }

    /**
     * @return array<string, string> Only replacements whose live schemas pass.
     */
    public function active(): array {
        $active = [];

        foreach ($this->configured() as $fallback => $replacement) {
            if (!$this->is_compatible($fallback, $replacement)) {
                continue;
            }

            $active[$fallback] = $replacement;
        }

        return $active;
    }

    public function preferred(string $ability_name): string {
        return $this->active()[$ability_name] ?? $ability_name;
    }

    /** @return list<string> */
    public function aliases(string $ability_name): array {
        $aliases = [$ability_name];

        foreach ($this->configured() as $fallback => $replacement) {
            if (!($ability_name === $fallback || $ability_name === $replacement)) {
                continue;
            }

            $aliases[] = $fallback;
            $aliases[] = $replacement;
        }

        return array_values(array_unique($aliases));
    }

    public function is_compatible(string $fallback, string $replacement): bool {
        if ('awpt/read-content' !== $fallback || 'core/read-content' !== $replacement) {
            return false;
        }

        if (!function_exists('wp_get_ability')) {
            return false;
        }

        $ability = wp_get_ability($replacement);

        if (!is_object($ability)) {
            return false;
        }

        $input = method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null;
        $output = method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null;

        if (!is_array($input) || !is_array($output)) {
            return false;
        }

        $properties = $this->properties($input);
        $field_enum = $this->string_list($properties['fields']['items']['enum'] ?? []);
        $output_properties = $this->properties($output);

        return (
            isset($properties['id'], $properties['fields'])
            && in_array('id', $field_enum, true)
            && in_array('content_raw', $field_enum, true)
            && isset($output_properties['id'], $output_properties['content_raw'])
        );
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array<string, mixed>
     */
    private function properties(array $schema): array {
        $properties = $this->string_keyed(is_array($schema['properties'] ?? null) ? $schema['properties'] : []);

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }

            foreach ($schema[$keyword] as $alternative) {
                if (!is_array($alternative)) {
                    continue;
                }

                $properties += $this->properties($alternative);
            }
        }

        return $properties;
    }

    /** @return list<string> */
    private function string_list(mixed $value): array {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private function string_keyed(array $value): array {
        $result = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
