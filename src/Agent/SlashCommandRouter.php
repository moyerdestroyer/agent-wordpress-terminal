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
    public function dispatch(string $message, int $session_id): array
    {
        $split = preg_split('/\s+/', trim($message));
        $parts = is_array($split) ? $split : [];
        $command = strtolower($parts[0] ?? '');
        $content_commands = new ContentCommandRouter();
        $site_commands = new SiteCommandRouter();
        $tool_commands = new ToolCommandRouter();
        $mcp_commands = new McpCommandRouter();
        $knowledge_commands = new KnowledgeCommandRouter();

        return match ($command) {
            '/clear' => [
                'content' => __('Transcript cleared for this session.', 'agent-wordpress-terminal'),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'clear',
            ],
            '/tools' => $tool_commands->tools(),
            '/mcp' => $mcp_commands->dispatch($parts),
            '/knowledge' => $knowledge_commands->dispatch($parts),
            '/context' => [
                'content' => __(
                    'Pins have been replaced by Knowledge retrieval. Try /knowledge search brand voice or /knowledge read 123.',
                    'agent-wordpress-terminal',
                ),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'knowledge',
            ],
            '/read' => $content_commands->read_content($parts),
            '/read-settings' => $site_commands->read_settings(),
            '/read-users' => $site_commands->read_users($parts),
            '/preview' => $content_commands->preview($parts),
            '/analyze' => $content_commands->analyze($parts),
            '/read-block-tree' => $content_commands->read_block_tree($parts),
            '/propose' => $content_commands->propose($session_id, $parts, trim($message)),
            default => [
                'content' => sprintf(
                    /* translators: %s: slash command */
                    __(
                        'Unknown command: %s. Try /tools, /mcp status, /mcp tools, /knowledge search brand voice, /read-settings, /read-users, /analyze 123, or /preview 123.',
                        'agent-wordpress-terminal',
                    ),
                    $command,
                ),
                'tool_calls' => [],
                'actions' => [],
            ],
        };
    }
}
