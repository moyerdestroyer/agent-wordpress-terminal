<?php

/**
 * Provider transport codec for WordPress Ability inputs.
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
 * Adapts arbitrary JSON-schema Ability inputs to object-only function tools.
 */
final class AbilityTransportCodec {
    public const VALUE_KEY = 'value';

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function provider_schema(array $schema): array {
        if ($this->uses_object_transport($schema)) {
            return AbilitySchemas::normalize_for_provider($schema);
        }

        return [
            'type' => 'object',
            'properties' => [
                self::VALUE_KEY => $schema,
            ],
            'required' => [self::VALUE_KEY],
            'additionalProperties' => false,
        ];
    }

    /**
     * Decode object-shaped provider arguments into the Ability's native input.
     *
     * @param array<string, mixed> $schema
     */
    public function ability_input(array $schema, mixed $provider_input): mixed {
        if ($this->uses_object_transport($schema)) {
            return $provider_input;
        }

        if (!is_array($provider_input) || !array_key_exists(self::VALUE_KEY, $provider_input)) {
            return null;
        }

        return $provider_input[self::VALUE_KEY];
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    public function uses_object_transport(array $schema): bool {
        if ([] === $schema) {
            return true;
        }

        if ('object' === ($schema['type'] ?? null) || is_array($schema['properties'] ?? null)) {
            return true;
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            $alternatives = $schema[$keyword] ?? null;

            if (!is_array($alternatives) || [] === $alternatives) {
                continue;
            }

            foreach ($alternatives as $alternative) {
                if (!is_array($alternative) || !$this->uses_object_transport($alternative)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
