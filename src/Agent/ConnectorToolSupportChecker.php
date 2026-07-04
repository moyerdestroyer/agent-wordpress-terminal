<?php

/**
 * Checks whether a WordPress AI Client connector supports AWPT's tool calling.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Uses the documented, cost-free `is_supported_for_text_generation()` builder check
 * (no network request) to report whether a connector's resolved model can still
 * generate text once AWPT's function declarations are attached — surfacing the same
 * mismatch that would otherwise only be discovered via a failed chat request.
 */
final class ConnectorToolSupportChecker
{
    /**
     * Whether the WordPress AI Client is available to check at all.
     */
    public function is_available(): bool
    {
        return function_exists('wp_ai_client_prompt');
    }

    /**
     * Whether a connector supports text generation with AWPT's tools attached.
     *
     * Defaults to `true` (assume support) whenever this can't be determined, so a
     * missing or partial AI Client implementation never produces a false warning.
     */
    public function supports_tools(string $connector_id): bool
    {
        if (!$this->is_available()) {
            return false;
        }

        try {
            $builder = call_user_func('wp_ai_client_prompt', '');

            if (!is_object($builder) || !method_exists($builder, 'using_provider')) {
                return true;
            }

            $configured = $builder->using_provider($connector_id);
            $configured = is_object($configured) ? $configured : $builder;
            $configured = $this->attach_tools($configured);

            if (!is_callable([$configured, 'is_supported_for_text_generation'])) {
                return true;
            }

            return (bool) $configured->is_supported_for_text_generation();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Attach AWPT's auto-executable ability tools to a prompt builder.
     */
    private function attach_tools(object $builder): object
    {
        $ability_names = new ToolRegistry()->get_auto_executable_ability_names();
        $declarations = new AbilityFunctionDeclarationBuilder()->build($ability_names);

        if ([] === $declarations || !method_exists($builder, 'using_function_declarations')) {
            return $builder;
        }

        $configured = $builder->using_function_declarations(...$declarations);

        return is_object($configured) ? $configured : $builder;
    }
}
