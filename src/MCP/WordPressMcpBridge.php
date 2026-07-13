<?php

/**
 * In-process bridge to the WordPress MCP Adapter / MCP-public abilities.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Detects the official WordPress MCP Adapter and fills AWPT's local MCP filter
 * contract without speaking the MCP network protocol.
 *
 * Same-site tools execute through the Abilities API in-process. External AI clients
 * still use the MCP Adapter's HTTP/STDIO transports; AWPT does not loopback to them.
 */
final class WordPressMcpBridge {
    /**
     * Default MCP Adapter server id used for REST URL display.
     */
    public const DEFAULT_SERVER_ID = 'mcp-adapter-default-server';

    /**
     * Register filter hooks that power status, discovery, and execution.
     */
    public function init(): void {
        add_filter('awpt_mcp_connected', [$this, 'filter_connected']);
        add_filter('awpt_mcp_server_url', [$this, 'filter_server_url']);
        add_filter('awpt_mcp_last_sync', [$this, 'filter_last_sync']);
        add_filter('awpt_mcp_tools', [$this, 'filter_tools']);
        add_filter('awpt_mcp_execute_tool', [$this, 'filter_execute'], 10, 4);
    }

    /**
     * Whether WordPress MCP integration appears available on this site.
     */
    public function is_available(): bool {
        return $this->is_adapter_class_available() || $this->is_mcp_rest_route_registered();
    }

    /**
     * @param bool $connected Existing connected flag.
     */
    public function filter_connected(bool $connected): bool {
        return $connected || $this->is_available();
    }

    /**
     * @param string $server_url Existing server URL.
     */
    public function filter_server_url(string $server_url): string {
        if ('' !== $server_url) {
            return $server_url;
        }

        return $this->default_server_url();
    }

    /**
     * @param string $last_sync Existing last-sync stamp.
     */
    public function filter_last_sync(string $last_sync): string {
        if ('' !== $last_sync || !$this->is_available()) {
            return $last_sync;
        }

        return function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
    }

    /**
     * Append MCP-facing abilities to the integration tool list.
     *
     * @param mixed $tools Existing tools from other integrations.
     * @return array<int, array<string, mixed>>
     */
    public function filter_tools(mixed $tools): array {
        $existing = is_array($tools) ? $tools : [];
        $catalog = new WordPressMcpCatalog();

        if (!$this->is_available()) {
            return $catalog->normalize_tools($existing);
        }

        return $catalog->merge_tools($existing, $catalog->list_tools());
    }

    /**
     * Execute an MCP tool by running the matching WordPress ability in-process.
     *
     * @param mixed                   $result Existing result from a higher-priority handler.
     * @param string                  $tool_name Tool / ability name.
     * @param array<array-key, mixed> $input Tool input.
     * @param array<string, mixed>    $tool Normalized tool metadata.
     * @return mixed
     */
    public function filter_execute(mixed $result, string $tool_name, array $input, array $tool): mixed {
        if (null !== $result || !$this->is_available()) {
            return $result;
        }

        $executed = new WordPressMcpCatalog()->execute($tool_name, $input, $tool);

        return null === $executed ? $result : $executed;
    }

    /**
     * Whether ability meta marks the ability as public for MCP default-server access.
     *
     * @param array<array-key, mixed> $meta Ability meta.
     */
    public static function is_public(array $meta): bool {
        $mcp = $meta['mcp'] ?? null;

        if (!is_array($mcp)) {
            return false;
        }

        return true === ($mcp['public'] ?? false);
    }

    /**
     * Normalize ability annotations into MCP tool annotation fields.
     *
     * @param array<array-key, mixed> $meta Ability meta.
     * @return array{readonly: bool|null, destructive: bool|null, requires_approval: bool|null}
     */
    public static function annotations(array $meta): array {
        $raw = $meta['annotations'] ?? null;
        $annotations = is_array($raw) ? $raw : [];

        return [
            'readonly' => self::optional_bool($annotations, 'readonly'),
            'destructive' => self::optional_bool($annotations, 'destructive'),
            'requires_approval' => self::optional_bool($annotations, 'requires_approval'),
        ];
    }

    /**
     * Append catalog tools onto an existing list without duplicate names.
     *
     * @param array<array-key, mixed>          $existing Existing tool definitions.
     * @param array<int, array<string, mixed>> $catalog Tools discovered from abilities.
     * @return array<int, array<string, mixed>>
     */
    public function merge_tools(array $existing, array $catalog): array {
        return new WordPressMcpCatalog()->merge_tools($existing, $catalog);
    }

    /**
     * Display URL for the default MCP HTTP endpoint when available.
     */
    private function default_server_url(): string {
        if (!$this->is_available() || !function_exists('rest_url')) {
            return '';
        }

        return rest_url('mcp/' . self::DEFAULT_SERVER_ID);
    }

    private function is_adapter_class_available(): bool {
        return (
            class_exists('\\WP\\MCP\\Core\\McpAdapter')
            || class_exists('\\WP\\MCP\\McpAdapter')
            || class_exists('WP_MCP_Adapter')
            || class_exists('\\Automattic\\WordpressMcp\\Plugin')
        );
    }

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

            if (str_starts_with($route, '/mcp/') || '/mcp' === $route) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $source Annotation map.
     */
    private static function optional_bool(array $source, string $key): ?bool {
        if (!array_key_exists($key, $source)) {
            return null;
        }

        $value = $source[$key];

        return is_bool($value) ? $value : (bool) $value;
    }
}
