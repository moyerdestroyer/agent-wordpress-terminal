<?php

/**
 * MCP status service.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reports MCP adapter connection status.
 */
final class StatusService
{
    /**
     * Get MCP status for the terminal header.
     *
     * @return array<string, mixed>
     */
    public function get_status(): array
    {
        $connected = (bool) apply_filters('awpt_mcp_connected', false);
        $server = (string) apply_filters('awpt_mcp_server_url', '');
        $tool_count = count(new Adapter()->list_tools());

        return [
            'connected' => $connected,
            'server_url' => $server,
            'tool_count' => $tool_count,
            'last_sync' => get_option('awpt_mcp_last_sync', ''),
            'label' => $connected
                ? __('Connected', 'agent-wordpress-terminal')
                : __('Disconnected', 'agent-wordpress-terminal'),
        ];
    }
}
