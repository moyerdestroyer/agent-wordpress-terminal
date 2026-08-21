<?php

/**
 * Provider transport codec for WordPress Ability inputs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\AbilitySchemas;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Adapts arbitrary JSON-schema Ability inputs to object-only function tools.
 */
final class AbilityTransportCodec {
    public const VALUE_KEY = 'value';

    private const JSON_OBJECT_DESCRIPTION = 'Send this value as a JSON-encoded object string.';

    /** @var array<string, array<string, mixed>> */
    private static array $provider_schema_cache = [];

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function provider_schema(array $schema): array {
        $encoded = wp_json_encode($schema);
        $cache_key = hash('sha256', is_string($encoded) ? $encoded : serialize($schema));

        if (array_key_exists($cache_key, self::$provider_schema_cache)) {
            return self::$provider_schema_cache[$cache_key];
        }

        if ($this->uses_object_transport($schema) && true !== ($schema['additionalProperties'] ?? null)) {
            $compiled = ArrayKey::string_map($this->strict_schema(
                AbilitySchemas::normalize_for_provider($schema),
                true,
            ));
            self::$provider_schema_cache[$cache_key] = $compiled;

            return $compiled;
        }

        $value_schema = $this->strict_schema($schema);

        $compiled = [
            'type' => 'object',
            'properties' => [
                self::VALUE_KEY => true === ($schema['additionalProperties'] ?? null)
                    ? $this->json_object_schema($schema)
                    : $value_schema,
            ],
            'required' => [self::VALUE_KEY],
            'additionalProperties' => false,
        ];
        self::$provider_schema_cache[$cache_key] = $compiled;

        return $compiled;
    }

    /**
     * Decode object-shaped provider arguments into the Ability's native input.
     *
     * @param array<string, mixed> $schema
     */
    public function ability_input(array $schema, mixed $provider_input): mixed {
        if ($this->uses_object_transport($schema) && true !== ($schema['additionalProperties'] ?? null)) {
            if (!is_array($provider_input)) {
                return new \WP_Error('awpt_provider_transport_invalid', __(
                    'Tool arguments must be an object.',
                    'agent-wordpress-terminal',
                ));
            }

            return $this->decode_value($schema, $provider_input, 'arguments');
        }

        if (!is_array($provider_input) || !array_key_exists(self::VALUE_KEY, $provider_input)) {
            return new \WP_Error('awpt_provider_transport_invalid', __(
                'Tool arguments are missing the provider value envelope.',
                'agent-wordpress-terminal',
            ));
        }

        if (true === ($schema['additionalProperties'] ?? null)) {
            return $this->decode_json_object($provider_input[self::VALUE_KEY], self::VALUE_KEY);
        }

        return $this->decode_value($schema, $provider_input[self::VALUE_KEY], self::VALUE_KEY);
    }

    /** @param array<array-key, mixed> $schema Encode native input snippets for provider-facing retry examples. */
    public function provider_input(array $schema, mixed $native_input): mixed {
        if ($this->uses_object_transport($schema) && true !== ($schema['additionalProperties'] ?? null)) {
            return $this->encode_value($schema, $native_input);
        }

        return [
            self::VALUE_KEY => true === ($schema['additionalProperties'] ?? null)
                ? wp_json_encode($native_input)
                : $this->encode_value($schema, $native_input),
        ];
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

    /**
     * Compile a native Ability schema into the strict subset accepted by
     * OpenAI-compatible function tools. Native optional fields become nullable;
     * the decoder removes null again before WordPress validates the Ability.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function strict_schema(array $schema, bool $root = false): array {
        if (true === ($schema['additionalProperties'] ?? null)) {
            return $this->json_object_schema($schema);
        }

        $type = $schema['type'] ?? null;
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : null;

        if ('object' === $type || null !== $properties || $root) {
            $properties = is_array($properties) ? $properties : [];
            $native_required = is_array($schema['required'] ?? null)
                ? array_fill_keys(array_values(array_filter($schema['required'], 'is_string')), true)
                : [];
            $compiled = [];

            foreach ($properties as $name => $property) {
                if (!is_string($name) || !is_array($property)) {
                    continue;
                }

                $compiled[$name] = $this->strict_schema($property);

                if (!array_key_exists($name, $native_required)) {
                    $compiled[$name] = $this->nullable_schema($compiled[$name]);
                }
            }

            $out = $this->schema_annotations($schema);
            $out['type'] = 'object';
            // JSON Schema requires `properties` to be an object. PHP's empty
            // array otherwise serializes as `[]`, which providers reject.
            $out['properties'] = [] === $compiled ? new \stdClass() : $compiled;
            $out['required'] = array_keys($compiled);
            $out['additionalProperties'] = false;

            return $out;
        }

        if ('array' === $type) {
            $out = $this->schema_annotations($schema);
            $out['type'] = 'array';
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
            $out['items'] = $this->strict_schema($items);

            return $out;
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }

            $alternatives = [];

            foreach ($schema[$keyword] as $alternative) {
                if (!is_array($alternative)) {
                    continue;
                }

                $alternatives[] = $this->strict_schema($alternative);
            }

            if ([] !== $alternatives) {
                return ['anyOf' => $alternatives] + $this->schema_annotations($schema);
            }
        }

        $allowed = [
            'type',
            'description',
            'enum',
            'const',
            'format',
            'pattern',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'multipleOf',
            'minLength',
            'maxLength',
        ];
        $out = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $schema)) {
                continue;
            }

            $out[$key] = $schema[$key];
        }

        if (!array_key_exists('type', $out)) {
            $out['type'] = 'string';
        }

        return $out;
    }

    /** @param array<array-key, mixed> $schema @return array<array-key, mixed> */
    private function nullable_schema(array $schema): array {
        if (is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];

            return $schema;
        }

        if (is_array($schema['type'] ?? null)) {
            if (!in_array('null', $schema['type'], true)) {
                $schema['type'][] = 'null';
            }

            return $schema;
        }

        $alternatives = is_array($schema['anyOf'] ?? null) ? $schema['anyOf'] : [$schema];
        $alternatives[] = ['type' => 'null'];

        return ['anyOf' => $alternatives];
    }

    /** @param array<array-key, mixed> $schema @return array<array-key, mixed> */
    private function json_object_schema(array $schema): array {
        $description = trim((string) ($schema['description'] ?? ''));

        return [
            'type' => 'string',
            'description' => trim($description . ' ' . self::JSON_OBJECT_DESCRIPTION),
        ];
    }

    /** @param array<array-key, mixed> $schema @return array<array-key, mixed> */
    private function schema_annotations(array $schema): array {
        $out = [];

        foreach (['description', 'minItems', 'maxItems', 'minProperties', 'maxProperties'] as $key) {
            if (!array_key_exists($key, $schema)) {
                continue;
            }

            $out[$key] = $schema[$key];
        }

        return $out;
    }

    /** @param array<array-key, mixed> $schema */
    private function decode_value(array $schema, mixed $value, string $path): mixed {
        if (true === ($schema['additionalProperties'] ?? null)) {
            return $this->decode_json_object($value, $path);
        }

        if (null === $value) {
            return null;
        }

        if ('array' === ($schema['type'] ?? null) && is_array($value)) {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
            $decoded = [];

            foreach ($value as $index => $item) {
                $next = $this->decode_value($items, $item, $path . '[' . $index . ']');

                if (is_wp_error($next)) {
                    return $next;
                }

                $decoded[] = $next;
            }

            return $decoded;
        }

        if ($this->uses_object_transport($schema) && is_array($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = is_array($schema['required'] ?? null)
                ? array_fill_keys(array_values(array_filter($schema['required'], 'is_string')), true)
                : [];
            $decoded = $value;

            foreach ($properties as $name => $property) {
                if (!is_string($name) || !is_array($property) || !array_key_exists($name, $decoded)) {
                    continue;
                }

                if (null === $decoded[$name] && !array_key_exists($name, $required)) {
                    unset($decoded[$name]);
                    continue;
                }

                $next = $this->decode_value($property, $decoded[$name], $path . '.' . $name);

                if (is_wp_error($next)) {
                    return $next;
                }

                $decoded[$name] = $next;
            }

            return $decoded;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $schema */
    private function encode_value(array $schema, mixed $value): mixed {
        if (true === ($schema['additionalProperties'] ?? null)) {
            $encoded = wp_json_encode($value);

            return is_string($encoded) ? $encoded : '{}';
        }

        if ('array' === ($schema['type'] ?? null) && is_array($value)) {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

            return array_map(fn(mixed $item): mixed => $this->encode_value($items, $item), $value);
        }

        if ($this->uses_object_transport($schema) && is_array($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $encoded = $value;

            foreach ($properties as $name => $property) {
                if (!is_string($name) || !is_array($property) || !array_key_exists($name, $encoded)) {
                    continue;
                }

                $encoded[$name] = $this->encode_value($property, $encoded[$name]);
            }

            return $encoded;
        }

        return $value;
    }

    private function decode_json_object(mixed $value, string $path): array|\WP_Error {
        if (!is_string($value)) {
            return new \WP_Error(
                'awpt_provider_transport_json_invalid',
                sprintf(__('Tool field %s must be a JSON-encoded object string.', 'agent-wordpress-terminal'), $path),
                ['field' => $path, 'retry_with' => [$path => '{}']],
            );
        }

        $decoded_object = json_decode($value);

        if (!$decoded_object instanceof \stdClass) {
            return new \WP_Error(
                'awpt_provider_transport_json_invalid',
                sprintf(__('Tool field %s must contain a valid JSON object.', 'agent-wordpress-terminal'), $path),
                ['field' => $path, 'retry_with' => [$path => '{}']],
            );
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
