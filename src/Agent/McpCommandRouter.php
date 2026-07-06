<?php

/**
 * MCP slash commands.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\MCP\Adapter;
use AWPT\MCP\StatusService;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles MCP status, discovery, and explicit tool calls.
 */
final class McpCommandRouter {
    /**
     * Handle /mcp command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function dispatch(array $parts): array {
        $subcommand = strtolower($parts[1] ?? 'status');

        return match ($subcommand) {
            'status' => $this->status(),
            'tools' => $this->tools(),
            'call' => $this->call($parts),
            default => [
                'content' => __(
                    'Usage: /mcp status, /mcp tools, or /mcp call tool/name {"key":"value"}',
                    'agent-wordpress-terminal',
                ),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'mcp',
            ],
        };
    }

    /**
     * Return MCP status.
     *
     * @return array<string, mixed>
     */
    private function status(): array {
        return [
            'content' => wp_json_encode(new StatusService()->get_status(), JSON_PRETTY_PRINT),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'mcp',
        ];
    }

    /**
     * Return discovered MCP tools.
     *
     * @return array<string, mixed>
     */
    private function tools(): array {
        $tools = new Adapter()->list_tools();

        return [
            'content' => [] === $tools
                ? __('No MCP tools are currently discovered.', 'agent-wordpress-terminal')
                : implode("\n", array_map(static fn(array $tool): string => sprintf(
                    '%s - %s',
                    (string) $tool['name'],
                    (string) ($tool['description'] ?? ''),
                ), $tools)),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'mcp',
        ];
    }

    /**
     * Execute an MCP tool by explicit slash command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    private function call(array $parts): array {
        $tool_name = sanitize_text_field($parts[2] ?? '');

        if ('' === $tool_name) {
            return $this->usage();
        }

        $input = $this->decode_input($parts);

        if (is_wp_error($input)) {
            return [
                'content' => $input->get_error_message(),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'mcp',
            ];
        }

        return $this->execute($tool_name, $input);
    }

    /**
     * Execute decoded MCP input.
     *
     * @param string                  $tool_name MCP tool name.
     * @param array<array-key, mixed> $input Tool input.
     * @return array<string, mixed>
     */
    private function execute(string $tool_name, array $input): array {
        $result = new Adapter()->execute_tool($tool_name, $input);

        if (is_wp_error($result)) {
            $message = $result->get_error_message();

            return [
                'content' => $message,
                'tool_calls' => [[
                    'tool' => $tool_name,
                    'input' => $input,
                    'output' => ['error' => $message],
                    'status' => 'failed',
                ]],
                'actions' => [],
                'command' => 'mcp',
            ];
        }

        return [
            'content' => wp_json_encode($result, JSON_PRETTY_PRINT),
            'tool_calls' => [[
                'tool' => $tool_name,
                'input' => $input,
                'output' => $result,
                'status' => 'success',
            ]],
            'actions' => [],
            'command' => 'mcp',
        ];
    }

    /**
     * Decode optional JSON input for /mcp call.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<array-key, mixed>|\WP_Error
     */
    private function decode_input(array $parts): array|\WP_Error {
        $json_input = trim(implode(' ', array_slice($parts, 3)));

        if ('' === $json_input) {
            return [];
        }

        $decoded = json_decode($json_input, true);

        if (!is_array($decoded)) {
            return new \WP_Error('awpt_mcp_invalid_input', __(
                'MCP tool input must be a JSON object.',
                'agent-wordpress-terminal',
            ));
        }

        return $decoded;
    }

    /**
     * Return MCP usage response.
     *
     * @return array<string, mixed>
     */
    private function usage(): array {
        return [
            'content' => __('Usage: /mcp call tool/name {"key":"value"}', 'agent-wordpress-terminal'),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'mcp',
        ];
    }
}
