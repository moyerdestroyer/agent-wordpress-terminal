<?php

/**
 * Detects WordPress MCP Adapter presence on the site.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Feature-detects the official MCP Adapter (or equivalent REST surface).
 */
final class WordPressMcpPresence {
    /**
     * Default MCP Adapter server id used for REST URL display.
     */
    public const DEFAULT_SERVER_ID = 'mcp-adapter-default-server';

    /**
     * Whether WordPress MCP integration appears available on this site.
     *
     * Availability means an MCP adapter (or its REST routes) is present — not merely
     * that some ability has a future `meta.mcp.public` flag.
     */
    public function is_available(): bool {
        return $this->is_adapter_class_available() || $this->is_mcp_rest_route_registered();
    }

    /**
     * Display URL for the default MCP HTTP endpoint when available.
     */
    public function default_server_url(): string {
        if (!$this->is_available() || !function_exists('rest_url')) {
            return '';
        }

        return rest_url('mcp/' . self::DEFAULT_SERVER_ID);
    }

    /**
     * Official / known MCP adapter class presence.
     */
    private function is_adapter_class_available(): bool {
        return (
            class_exists('\\WP\\MCP\\Core\\McpAdapter')
            || class_exists('\\WP\\MCP\\McpAdapter')
            || class_exists('WP_MCP_Adapter')
            || class_exists('\\Automattic\\WordpressMcp\\Plugin')
        );
    }

    /**
     * Fallback: MCP REST namespace already registered by another plugin.
     */
    private function is_mcp_rest_route_registered(): bool {
        if (!function_exists('rest_get_server')) {
            return false;
        }

        $server = rest_get_server();
        $routes = $server->get_routes();

        foreach (array_keys($routes) as $route) {
            if (!is_string($route)) {
                continue;
            }

            // Official adapter serves under /wp-json/mcp/{server-id}.
            if (str_starts_with($route, '/mcp/') || '/mcp' === $route) {
                return true;
            }
        }

        return false;
    }
}
