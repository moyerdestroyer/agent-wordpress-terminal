<?php

/**
 * Executes MCP-facing WordPress abilities in-process.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

use AWPT\Agent\ToolExecutor;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves tool names to abilities and runs them via ToolExecutor.
 */
final class WordPressMcpAbilityRunner {
    /**
     * Ability namespaces always treated as MCP-facing.
     *
     * @var list<string>
     */
    private const ADAPTER_ABILITY_PREFIXES = [
        'mcp-adapter/',
        'mcp/',
    ];

    /**
     * Execute a tool by running the matching WordPress ability.
     *
     * @param string                  $tool_name Tool / ability name.
     * @param array<array-key, mixed> $input Tool input.
     * @param array<string, mixed>    $tool Normalized tool metadata.
     * @return array<array-key, mixed>|\WP_Error|null Null when this runner does not own the tool.
     */
    public function execute(string $tool_name, array $input, array $tool): array|\WP_Error|null {
        $ability_name = $this->resolve_ability_name($tool_name, $tool);

        if (null === $ability_name || !$this->is_mcp_facing_ability_name($ability_name)) {
            return null;
        }

        return new ToolExecutor()->execute($ability_name, $this->string_keyed_input($input));
    }

    /**
     * @param string $ability_name Ability name.
     */
    public function is_adapter_namespace(string $ability_name): bool {
        foreach (self::ADAPTER_ABILITY_PREFIXES as $prefix) {
            if (str_starts_with($ability_name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string               $tool_name Requested tool name.
     * @param array<string, mixed> $tool Tool metadata.
     */
    private function resolve_ability_name(string $tool_name, array $tool): ?string {
        $from_meta = $tool['ability'] ?? null;
        $ability_from_meta = is_string($from_meta) ? $from_meta : null;

        if (null !== $ability_from_meta && '' !== $ability_from_meta) {
            return $ability_from_meta;
        }

        if ('' === $tool_name) {
            return null;
        }

        if (function_exists('wp_get_ability') && null !== wp_get_ability($tool_name)) {
            return $tool_name;
        }

        // MCP clients sometimes see slash-converted names (namespace/ability → namespace-ability).
        if (str_contains($tool_name, '-') && !str_contains($tool_name, '/')) {
            $slash_name = preg_replace('/-/', '/', $tool_name, 1);

            if (is_string($slash_name) && function_exists('wp_get_ability') && null !== wp_get_ability($slash_name)) {
                return $slash_name;
            }
        }

        return $tool_name;
    }

    /**
     * @param string $ability_name Ability name.
     */
    private function is_mcp_facing_ability_name(string $ability_name): bool {
        if ($this->is_adapter_namespace($ability_name)) {
            return true;
        }

        if (!function_exists('wp_get_ability')) {
            return false;
        }

        $ability = wp_get_ability($ability_name);

        return is_object($ability) && WordPressMcpMeta::ability_is_public($ability);
    }

    /**
     * @param array<array-key, mixed> $input Raw tool input.
     * @return array<string, mixed>
     */
    private function string_keyed_input(array $input): array {
        $normalized = [];

        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
