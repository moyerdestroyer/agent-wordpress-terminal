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
     * Core owns normalization, validation, permissions, lifecycle hooks, callback
     * execution, and output validation. Calling those methods separately here
     * would make WordPress 7.1 lifecycle filters run more than once.
     */
    public function execute(string $tool_name, mixed $input): mixed {
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

        return $ability->execute($input);
    }
}
