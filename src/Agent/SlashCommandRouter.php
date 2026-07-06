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
                __('You can usually ask in plain language:', 'agent-wordpress-terminal'),
                __('Focus the About page', 'agent-wordpress-terminal'),
                __('Preview the homepage', 'agent-wordpress-terminal'),
                __('Find brand voice guidance', 'agent-wordpress-terminal'),
                '',
                __('Useful shortcuts:', 'agent-wordpress-terminal'),
                __('/focus about - set focus by title, slug, URL, or ID', 'agent-wordpress-terminal'),
                __('/preview about - open a preview by title, slug, URL, or ID', 'agent-wordpress-terminal'),
                __('/knowledge search brand voice - search indexed Knowledge', 'agent-wordpress-terminal'),
                __('/tools - list registered abilities and MCP tools', 'agent-wordpress-terminal'),
                __('/mcp status - show MCP connection status', 'agent-wordpress-terminal'),
                __('/clear - clear this session transcript', 'agent-wordpress-terminal'),
            ]),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'help',
        ];
    }
}
