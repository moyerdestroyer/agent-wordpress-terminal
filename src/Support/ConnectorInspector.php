<?php

/**
 * Connector status inspection helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Inspects installed connector plugins and credential state.
 */
final class ConnectorInspector
{
    /**
     * Resolve a connector display name from registry data.
     *
     * @param string               $connector_id Connector ID.
     * @param array<string, mixed> $data Connector registry data.
     */
    public function connector_name_from_data(string $connector_id, array $data): string
    {
        if (is_string($data['name'] ?? null) && '' !== $data['name']) {
            return $data['name'];
        }

        return $connector_id;
    }

    /**
     * Whether the connector plugin package is installed.
     *
     * @param array<string, mixed> $data Connector registry data.
     */
    public function is_installed(array $data): bool
    {
        $plugin_file = $this->plugin_file_from_data($data);

        if ('' === $plugin_file) {
            return true;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return array_key_exists($plugin_file, get_plugins());
    }

    /**
     * Build connector status metadata.
     *
     * @param string               $connector_id Connector ID.
     * @param array<string, mixed> $data Connector registry data.
     * @return array{active: bool, authenticated: bool, ready: bool, status: string, status_label: string}
     */
    public function build_status(string $connector_id, array $data): array
    {
        $active = $this->is_active($data);
        $authenticated = $this->has_authentication($connector_id);
        $ready = $active && $authenticated;

        if ($ready) {
            return [
                'active' => true,
                'authenticated' => true,
                'ready' => true,
                'status' => 'ready',
                'status_label' => __('Ready', 'agent-wordpress-terminal'),
            ];
        }

        if (!$active) {
            return [
                'active' => false,
                'authenticated' => $authenticated,
                'ready' => false,
                'status' => 'inactive',
                'status_label' => __('Inactive', 'agent-wordpress-terminal'),
            ];
        }

        return [
            'active' => true,
            'authenticated' => false,
            'ready' => false,
            'status' => 'not_configured',
            'status_label' => __('Key not configured', 'agent-wordpress-terminal'),
        ];
    }

    /**
     * Whether the connector plugin is active.
     *
     * @param array<string, mixed> $data Connector registry data.
     */
    private function is_active(array $data): bool
    {
        if (function_exists('WordPress\AI\is_connector_plugin_active')) {
            return \WordPress\AI\is_connector_plugin_active($data);
        }

        $plugin_file = $this->plugin_file_from_data($data);

        if ('' === $plugin_file) {
            return true;
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return (
            is_plugin_active($plugin_file)
            || is_multisite()
            && function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network($plugin_file)
        );
    }

    /**
     * Whether connector credentials are present.
     */
    private function has_authentication(string $connector_id): bool
    {
        return (
            function_exists('WordPress\AI\has_connector_authentication')
            && \WordPress\AI\has_connector_authentication($connector_id)
        );
    }

    /**
     * Extract the connector plugin file path from registry data.
     *
     * @param array<string, mixed> $data Connector registry data.
     */
    private function plugin_file_from_data(array $data): string
    {
        $plugin = $data['plugin'] ?? null;

        if (!is_array($plugin) || !is_string($plugin['file'] ?? null)) {
            return '';
        }

        return $plugin['file'];
    }
}
