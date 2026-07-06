<?php

/**
 * Builds WordPress AI Client function declarations from AWPT abilities.
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
 * Shared helper for translating AWPT ability names into provider-safe function
 * declarations, used both when actually prompting the model and when pre-flight
 * checking whether a connector supports function calling at all.
 */
final class AbilityFunctionDeclarationBuilder {
    /**
     * Build provider-safe function declarations for AWPT abilities.
     *
     * @param list<string> $ability_names Ability names exposed to the model.
     * @return list<object>
     */
    public function build(array $ability_names): array {
        if (!function_exists('wp_get_ability') || !class_exists('WP_AI_Client_Ability_Function_Resolver')) {
            return [];
        }

        $declarations = [];

        foreach ($ability_names as $ability_name) {
            $ability = wp_get_ability($ability_name);

            if (null === $ability) {
                continue;
            }

            $function_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name($ability_name);
            $raw_schema = method_exists($ability, 'get_input_schema')
                ? $ability->get_input_schema()
                : AbilitySchemas::empty_object_input();
            $normalized_schema = AbilitySchemas::normalize_for_provider($raw_schema);

            $declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
                $function_name,
                $ability->get_description(),
                $normalized_schema,
            );
        }

        return $declarations;
    }
}
