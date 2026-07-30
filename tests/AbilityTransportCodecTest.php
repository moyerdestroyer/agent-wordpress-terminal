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
test_tool_executor_delegates_lifecycle_once_and_returns_scalar_output();
