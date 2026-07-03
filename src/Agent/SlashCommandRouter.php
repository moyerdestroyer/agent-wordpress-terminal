<?php

/**
 * Slash command router.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles terminal slash commands.
 */
final class SlashCommandRouter
{
    /**
     * Route a slash command.
     *
     * @param string $message User message.
     * @return array<string, mixed>
     */
    public function dispatch(string $message): array
    {
        $split = preg_split('/\s+/', trim($message));
        $parts = is_array($split) ? $split : [];
        $command = strtolower($parts[0] ?? '');

        return match ($command) {
            '/help' => $this->help(),
            '/clear' => [
                'content' => __('Transcript cleared for this session.', 'agent-wordpress-terminal'),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'clear',
            ],
            '/tools' => new ToolCommandRouter()->tools(),
            '/mcp' => new McpCommandRouter()->dispatch($parts),
            '/knowledge' => new KnowledgeCommandRouter()->dispatch($parts),
            '/focus' => new ContentCommandRouter()->focus($parts),
            '/preview' => new ContentCommandRouter()->preview($parts),
            default => [
                'content' => sprintf(
                    /* translators: %s: slash command */
                    __('Unknown command: %s. Try /help.', 'agent-wordpress-terminal'),
                    $command,
                ),
                'tool_calls' => [],
                'actions' => [],
            ],
        };
    }

    /**
     * Return the user-facing slash command list.
     *
     * @return array<string, mixed>
     */
    private function help(): array
    {
        return [
            'content' => implode("\n", [
                __('Slash commands:', 'agent-wordpress-terminal'),
                __('/focus 123 - set the session focus to a post or page', 'agent-wordpress-terminal'),
                __('/preview 123 - load a post preview in the preview workspace', 'agent-wordpress-terminal'),
                __('/knowledge search brand voice - search indexed Knowledge', 'agent-wordpress-terminal'),
                __('/knowledge read 123 - load a Knowledge item by post ID', 'agent-wordpress-terminal'),
                __('/tools - list registered abilities and MCP tools', 'agent-wordpress-terminal'),
                __('/mcp status - show MCP connection status', 'agent-wordpress-terminal'),
                __('/mcp tools - list MCP tools', 'agent-wordpress-terminal'),
                __('/mcp call tool/name {"key":"value"} - run a specific MCP tool', 'agent-wordpress-terminal'),
                __('/clear - clear this session transcript', 'agent-wordpress-terminal'),
            ]),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'help',
        ];
    }
}
