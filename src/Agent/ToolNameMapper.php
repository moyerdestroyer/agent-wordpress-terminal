<?php

/**
 * Maps ability/MCP tool names to provider function names and back.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * OpenAI-compatible function names cannot include `/`, so each Ability name
 * segment is separated with `__` and hyphens are flattened to underscores.
 */
final class ToolNameMapper {
    /**
     * Ability / MCP tool name → provider function name.
     */
    public function to_function_name(string $tool_name): string {
        $tool_name = trim($tool_name);

        if ('' === $tool_name) {
            return '';
        }

        return str_replace(['/', '-'], ['__', '_'], $tool_name);
    }

    /**
     * Provider function name → ability / MCP tool name.
     *
     * Reversible for the two-to-four-segment Ability names supported by
     * WordPress 7.1.
     */
    public function to_tool_name(string $function_name): string {
        $function_name = trim($function_name);

        if ('' === $function_name) {
            return '';
        }

        if (!str_contains($function_name, '__')) {
            return $function_name;
        }

        $segments = explode('__', $function_name);
        $segments = array_map(static fn(string $segment): string => str_replace('_', '-', $segment), $segments);

        return implode('/', $segments);
    }

    /**
     * Convert the optional WordPress AI Client transport form back to AWPT's
     * canonical ability name without depending on its resolver implementation.
     */
    public function from_wordpress_ability_function_name(string $function_name): string {
        if (!str_starts_with($function_name, 'wpab__')) {
            return '';
        }

        return $this->to_tool_name(substr($function_name, strlen('wpab__')));
    }
}
