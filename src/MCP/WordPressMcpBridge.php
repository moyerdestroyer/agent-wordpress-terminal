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
    private WordPressMcpPresence $presence;

    private WordPressMcpAbilityCatalog $catalog;

    public function __construct(?WordPressMcpPresence $presence = null, ?WordPressMcpAbilityCatalog $catalog = null) {
        $this->presence = $presence ?? new WordPressMcpPresence();
        $this->catalog = $catalog ?? new WordPressMcpAbilityCatalog();
    }

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
        return $this->presence->is_available();
    }

    /**
     * @param bool $connected Existing connected flag.
     */
    public function filter_connected(bool $connected): bool {
        return $connected || $this->presence->is_available();
    }

    /**
     * @param string $server_url Existing server URL.
     */
    public function filter_server_url(string $server_url): string {
        if ('' !== $server_url) {
            return $server_url;
        }

        return $this->presence->default_server_url();
    }

    /**
     * @param string $last_sync Existing last-sync stamp.
     */
    public function filter_last_sync(string $last_sync): string {
        if ('' !== $last_sync || !$this->presence->is_available()) {
            return $last_sync;
        }

        // Live discovery is in-process; surface "now" without writing an option on every poll.
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

        if (!$this->presence->is_available()) {
            return $this->catalog->normalize_tools($existing);
        }

        return $this->catalog->merge_tools($existing);
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
        if (null !== $result || !$this->presence->is_available()) {
            return $result;
        }

        $executed = $this->catalog->execute($tool_name, $input, $tool);

        return null === $executed ? $result : $executed;
    }
}
