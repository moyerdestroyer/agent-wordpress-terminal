<?php

/**
 * Tests native Ability JSON transport and single-dispatch execution.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\AbilityTransportCodec;
use AWPT\Agent\ToolExecutor;

function test_ability_transport_codec_preserves_object_inputs(): void {
    $codec = new AbilityTransportCodec();
    $schema = [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required' => ['id'],
    ];

    $provider = $codec->provider_schema($schema);
    Assert::same('object', $provider['type'] ?? null, 'object schemas should retain their provider type');
    Assert::same($schema['properties'], $provider['properties'] ?? null, 'object properties should remain intact');
    Assert::same(['id' => 7], $codec->ability_input($schema, ['id' => 7]), 'object input should not be wrapped');
}

function test_ability_transport_codec_wraps_arbitrary_json_inputs(): void {
    $codec = new AbilityTransportCodec();
    $schema = ['type' => 'string', 'minLength' => 1];
    $provider = $codec->provider_schema($schema);

    Assert::same('object', $provider['type'] ?? null, 'provider tools require an object envelope');
    Assert::same($schema, $provider['properties']['value'] ?? null, 'native schema should live under value');
    Assert::same('hello', $codec->ability_input($schema, ['value' => 'hello']), 'string input should unwrap');
}

function test_ability_transport_codec_compiles_and_round_trips_strict_inputs(): void {
    $codec = new AbilityTransportCodec();
    $schema = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'label' => ['type' => 'string'],
            'changes' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'kind' => ['type' => 'string'],
                        'attrs' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['kind'],
                ],
            ],
        ],
        'required' => ['id', 'changes'],
    ];

    $provider = $codec->provider_schema($schema);
    Assert::same(['id', 'label', 'changes'], $provider['required'] ?? null, 'strict root requires every property');
    Assert::same(['string', 'null'], $provider['properties']['label']['type'] ?? null, 'optional scalar is nullable');
    Assert::same(
        ['string', 'null'],
        $provider['properties']['changes']['items']['properties']['attrs']['type'] ?? null,
        'optional open-ended nested maps use a nullable strict JSON-string transport',
    );
    Assert::same(
        ['kind', 'attrs'],
        $provider['properties']['changes']['items']['required'] ?? null,
        'nested strict objects require optional nullable properties too',
    );

    $native = ['id' => 7, 'changes' => [['kind' => 'set', 'attrs' => ['level' => 2]]]];
    $encoded = $codec->provider_input($schema, $native);
    Assert::same('{"level":2}', $encoded['changes'][0]['attrs'] ?? null, 'dynamic map encodes deterministically');
    Assert::same($native, $codec->ability_input($schema, $encoded), 'strict transport round-trips native input');

    $decoded_null = $codec->ability_input($schema, [
        'id' => 7,
        'label' => null,
        'changes' => [['kind' => 'remove', 'attrs' => null]],
    ]);
    Assert::false(isset($decoded_null['label']), 'native optional root field is restored as absent');
    Assert::false(isset($decoded_null['changes'][0]['attrs']), 'native optional nested field is restored as absent');

    $decoded_empty = $codec->ability_input($schema, [
        'id' => 7,
        'label' => null,
        'changes' => [['kind' => 'insert', 'attrs' => '{}']],
    ]);
    Assert::same([], $decoded_empty['changes'][0]['attrs'] ?? null, 'an encoded empty JSON object is valid');

    $invalid = $codec->ability_input($schema, [
        'id' => 7,
        'label' => null,
        'changes' => [['kind' => 'set', 'attrs' => '{bad json']],
    ]);
    Assert::true(is_wp_error($invalid), 'invalid encoded maps fail before Ability execution');
    if (is_wp_error($invalid)) {
        Assert::same('awpt_provider_transport_json_invalid', $invalid->get_error_code(), 'transport error is explicit');
    }
}

function test_ability_transport_codec_serializes_empty_schema_properties_as_objects(): void {
    $provider = new AbilityTransportCodec()->provider_schema([
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => ['type' => 'object'],
            ],
        ],
    ]);
    $encoded = (string) wp_json_encode($provider);

    Assert::false(str_contains($encoded, '"properties":[]'), 'empty schema properties serialize as JSON objects');
    Assert::true(str_contains($encoded, '"properties":{}'), 'empty schema properties retain object shape');
}

function test_tool_executor_delegates_lifecycle_once_and_returns_scalar_output(): void {
    awpt_test_reset_state();
    $ability = wp_register_ability('demo/scalar', [
        'input_schema' => ['type' => 'string'],
        'output_schema' => ['type' => 'integer'],
        'execute_callback' => static fn(mixed $input): int => strlen((string) $input),
    ]);

    $result = new ToolExecutor()->execute('demo/scalar', 'hello');

    Assert::same(5, $result, 'scalar ability output should pass through unchanged');
    Assert::same(1, $ability->execute_count, 'AWPT should invoke WP_Ability::execute exactly once');
}

test_ability_transport_codec_preserves_object_inputs();
test_ability_transport_codec_wraps_arbitrary_json_inputs();
test_ability_transport_codec_compiles_and_round_trips_strict_inputs();
test_ability_transport_codec_serializes_empty_schema_properties_as_objects();
test_tool_executor_delegates_lifecycle_once_and_returns_scalar_output();
