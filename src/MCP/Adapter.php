<?php

/**
 * Filter-backed MCP adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Provides a small MCP discovery and execution contract for integrations.
 */
final class Adapter
{
    /**
     * Return MCP tools exposed by integrations.
     *
     * Integrations may hook `awpt_mcp_tools` and return tool arrays with:
     * name, label, description, input_schema, output_schema, permission,
     * readonly, destructive, requires_approval.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_tools(): array
    {
        $raw_tools = apply_filters('awpt_mcp_tools', []);

        if (!is_array($raw_tools)) {
            return [];
        }

        $tools = [];

        foreach ($raw_tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            $normalized = $this->normalize_tool($tool);

            if (null !== $normalized) {
                $tools[] = $normalized;
            }
        }

        return $tools;
    }

    /**
     * Execute a non-destructive MCP tool through integration filters.
     *
     * @param string               $tool_name MCP tool name.
     * @param array<array-key, mixed> $input Tool input.
     * @return array<array-key, mixed>|\WP_Error
     */
    public function execute_tool(string $tool_name, array $input): array|\WP_Error
    {
        $tool = $this->find_tool($tool_name);

        if (null === $tool) {
            return new \WP_Error('awpt_mcp_tool_not_found', __('MCP tool not found.', 'agent-wordpress-terminal'));
        }

        if (
            is_bool($tool['destructive'] ?? null) && $tool['destructive']
            || is_bool($tool['requires_approval'] ?? null) && $tool['requires_approval']
        ) {
            return new \WP_Error('awpt_mcp_tool_requires_approval', __(
                'This MCP tool requires an explicit staged approval workflow before execution.',
                'agent-wordpress-terminal',
            ));
        }

        /**
         * Execute an MCP tool.
         *
         * Return an array result or WP_Error. The default null means no integration handled the tool.
         *
         * @param mixed                $result Tool result.
         * @param string               $tool_name Tool name.
         * @param array<array-key, mixed> $input Tool input.
         * @param array<string, mixed> $tool Tool metadata.
         */
        $result = apply_filters('awpt_mcp_execute_tool', null, $tool_name, $input, $tool);

        if (is_wp_error($result)) {
            return $result;
        }

        if (!is_array($result)) {
            return new \WP_Error('awpt_mcp_tool_unhandled', __(
                'No MCP integration handled this tool execution.',
                'agent-wordpress-terminal',
            ));
        }

        return $result;
    }

    /**
     * Find a normalized MCP tool by name.
     *
     * @param string $tool_name Tool name.
     * @return array<array-key, mixed>|null
     */
    public function find_tool(string $tool_name): ?array
    {
        foreach ($this->list_tools() as $tool) {
            if ($tool_name === $tool['name']) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Normalize a tool definition for REST/UI output.
     *
     * @param array<string, mixed> $tool Raw tool.
     * @return array<string, mixed>|null
     */
    private function normalize_tool(array $tool): ?array
    {
        $name = sanitize_text_field((string) ($tool['name'] ?? ''));

        if ('' === $name) {
            return null;
        }

        $label = sanitize_text_field((string) ($tool['label'] ?? $name));
        $input_schema = is_array($tool['input_schema'] ?? null)
            ? $tool['input_schema']
            : [
                'type' => 'object',
                'properties' => [],
            ];
        $output_schema = is_array($tool['output_schema'] ?? null) ? $tool['output_schema'] : null;

        return [
            'name' => $name,
            'label' => '' !== $label ? $label : $name,
            'description' => sanitize_textarea_field((string) ($tool['description'] ?? '')),
            'category' => sanitize_text_field((string) ($tool['category'] ?? 'mcp')),
            'input_schema' => $input_schema,
            'output_schema' => $output_schema,
            'permission' => sanitize_text_field((string) ($tool['permission'] ?? 'manage_options')),
            'readonly' => array_key_exists('readonly', $tool) ? (bool) $tool['readonly'] : null,
            'destructive' => array_key_exists('destructive', $tool) ? (bool) $tool['destructive'] : null,
            'requires_approval' => array_key_exists('requires_approval', $tool)
                ? (bool) $tool['requires_approval']
                : null,
        ];
    }
}
