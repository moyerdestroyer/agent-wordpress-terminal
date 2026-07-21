<?php

/**
 * JSON Schema helpers for AWPT abilities.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds provider-safe JSON Schema fragments for ability registration.
 */
final class AbilitySchemas {
    /**
     * Keywords OpenAI-compatible function tools reject at the schema root.
     *
     * @var list<string>
     */
    private const FORBIDDEN_TOP_LEVEL_KEYWORDS = ['oneOf', 'anyOf', 'allOf', 'enum', 'const', 'not'];

    /**
     * Schema for abilities that accept no input.
     *
     * @return array<string, mixed>
     */
    public static function empty_object_input(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
        ];
    }

    /**
     * Normalize a schema before sending it to OpenAI-compatible providers.
     *
     * @param array<string, mixed> $schema Ability input schema.
     * @return array<string, mixed>
     */
    public static function normalize_for_provider(array $schema): array {
        if ([] === $schema) {
            return self::empty_object_input();
        }

        $properties = self::collect_properties($schema);
        $required = self::string_list($schema['required'] ?? []);

        foreach (self::FORBIDDEN_TOP_LEVEL_KEYWORDS as $keyword) {
            unset($schema[$keyword]);
        }

        $schema['type'] = 'object';

        if ([] !== $properties) {
            $schema['properties'] = $properties;
        } else {
            unset($schema['properties']);
        }

        $required = array_values(array_filter($required, static fn(string $name): bool => array_key_exists(
            $name,
            $properties,
        )));

        if ([] !== $required) {
            $schema['required'] = $required;
        } else {
            unset($schema['required']);
        }

        $schema['additionalProperties'] ??= false;

        return $schema;
    }

    /**
     * Flatten properties from top-level object alternatives.
     *
     * Provider declarations are intentionally lenient; the Ability applies its
     * authoritative input validation when the selected tool is executed.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function collect_properties(array $schema): array {
        $properties = is_array($schema['properties'] ?? null) ? self::string_keyed($schema['properties']) : [];

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }

            foreach (array_filter($schema[$keyword], 'is_array') as $alternative) {
                $properties += self::collect_properties(self::string_keyed($alternative));
            }
        }

        return $properties;
    }

    /**
     * @return list<string>
     */
    private static function string_list(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function string_keyed(array $value): array {
        $out = [];

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                continue;
            }

            $out[$key] = $value[$key];
        }

        return $out;
    }
}
