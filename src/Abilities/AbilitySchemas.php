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

        if (!array_key_exists('type', $schema)) {
            $schema['type'] = 'object';
        }

        if (
            array_key_exists('properties', $schema)
            && is_array($schema['properties'])
            && [] === $schema['properties']
        ) {
            unset($schema['properties']);
            $schema['additionalProperties'] ??= false;
        }

        return $schema;
    }
}
