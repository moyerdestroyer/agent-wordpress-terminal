<?php

/**
 * Tool executor.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Executes WordPress abilities as agent tools.
 */
final class ToolExecutor {
    /**
     * Execute a registered ability.
     *
     * @param string               $tool_name Ability name.
     * @param array<string, mixed> $input Ability input.
     * @return array<array-key, mixed>|\WP_Error
     */
    public function execute(string $tool_name, array $input): array|\WP_Error {
        if (!function_exists('wp_get_ability')) {
            return new \WP_Error('awpt_abilities_unavailable', __(
                'WordPress Abilities API is not available.',
                'agent-wordpress-terminal',
            ));
        }

        /** @var \WP_Ability|null $ability */
        $ability = wp_get_ability($tool_name);

        if (null === $ability) {
            return new \WP_Error('awpt_ability_not_found', sprintf(
                /* translators: %s: ability name */
                __('Ability "%s" is not registered.', 'agent-wordpress-terminal'),
                $tool_name,
            ));
        }

        $normalized_input = method_exists($ability, 'normalize_input') ? $ability->normalize_input($input) : $input;

        if (method_exists($ability, 'validate_input')) {
            $validation = $ability->validate_input($normalized_input);

            if (is_wp_error($validation)) {
                return $validation;
            }
        }

        $permission = $ability->check_permissions($normalized_input);

        if (is_wp_error($permission)) {
            return $permission;
        }

        if (false === $permission) {
            return new \WP_Error('awpt_ability_forbidden', sprintf(
                /* translators: %s: ability name */
                __('You do not have permission to run ability "%s".', 'agent-wordpress-terminal'),
                $tool_name,
            ));
        }

        $result = $ability->execute($normalized_input);

        if (is_wp_error($result)) {
            return $result;
        }

        if (!is_array($result)) {
            return new \WP_Error('awpt_ability_invalid_output', sprintf(
                /* translators: %s: ability name */
                __('Ability "%s" returned an invalid output type.', 'agent-wordpress-terminal'),
                $tool_name,
            ));
        }

        return $result;
    }
}
