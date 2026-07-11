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
 * OpenAI-compatible function names cannot include `/`, so `namespace/tool`
 * becomes `namespace__tool` with hyphens flattened to underscores.
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
     * Reversible for the standard `namespace/rest-of-name` shape used by Abilities.
     */
    public function to_tool_name(string $function_name): string {
        $function_name = trim($function_name);

        if ('' === $function_name) {
            return '';
        }

        $separator = strpos($function_name, '__');

        if (false === $separator) {
            return $function_name;
        }

        $namespace = substr($function_name, 0, $separator);
        $rest = substr($function_name, $separator + 2);

        return $namespace . '/' . str_replace('_', '-', $rest);
    }
}
