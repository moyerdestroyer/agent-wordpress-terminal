<?php

/**
 * Tests for provider-safe Ability schema normalization.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\AbilitySchemas;

function test_ability_schema_flattens_top_level_one_of(): void {
    $schema = [
        'type' => 'object',
        'oneOf' => [
            [
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'fields' => ['type' => 'array'],
                ],
            ],
            [
                'required' => ['email'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'fields' => ['type' => 'array'],
                ],
            ],
            [
                'properties' => [
                    'roles' => ['type' => 'array'],
                ],
            ],
        ],
    ];

    $normalized = AbilitySchemas::normalize_for_provider($schema);

    Assert::same('object', $normalized['type'], 'provider function schemas must be objects');
    Assert::false(array_key_exists('oneOf', $normalized), 'top-level oneOf must not reach the provider');
    Assert::true(isset($normalized['properties']['id']), 'properties from the ID alternative should be retained');
    Assert::true(isset($normalized['properties']['email']), 'properties from the email alternative should be retained');
    Assert::true(isset($normalized['properties']['roles']), 'query properties should be retained');
    Assert::false(array_key_exists('required', $normalized), 'alternative-specific required fields must stay optional');
}

function test_ability_schema_forces_object_and_removes_forbidden_keywords(): void {
    $normalized = AbilitySchemas::normalize_for_provider([
        'type' => 'string',
        'enum' => ['one', 'two'],
        'const' => 'one',
        'not' => ['type' => 'null'],
    ]);

    Assert::same(
        'object',
        $normalized['type'],
        'non-object Ability inputs should degrade to a safe object declaration',
    );

    foreach (['enum', 'const', 'not'] as $keyword) {
        Assert::false(
            array_key_exists($keyword, $normalized),
            sprintf('top-level %s must not reach the provider', $keyword),
        );
    }
}

function test_ability_schema_preserves_direct_required_fields(): void {
    $normalized = AbilitySchemas::normalize_for_provider([
        'type' => 'object',
        'required' => ['query', 'missing'],
        'properties' => [
            'query' => ['type' => 'string'],
        ],
    ]);

    Assert::same(['query'], $normalized['required'], 'required fields should be limited to declared properties');
}

test_ability_schema_flattens_top_level_one_of();
test_ability_schema_forces_object_and_removes_forbidden_keywords();
test_ability_schema_preserves_direct_required_fields();
