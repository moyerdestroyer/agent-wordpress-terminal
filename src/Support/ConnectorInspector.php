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
final class ConnectorInspector {
    /**
     * Resolve a connector display name from registry data.
     *
     * @param string               $connector_id Connector ID.
     * @param array<string, mixed> $data Connector registry data.
     */
    public function connector_name_from_data(string $connector_id, array $data): string {
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
    public function is_installed(array $data): bool {
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
    public function build_status(string $connector_id, array $data): array {
        $active = $this->is_active($data);
        $authenticated = $this->has_authentication($connector_id, $data);
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
     * Prefers the connector's own `plugin.is_active` callback (the documented WordPress
     * Core Connectors API contract), falling back to a standard plugin-file lookup.
     *
     * @param array<string, mixed> $data Connector registry data.
     */
    private function is_active(array $data): bool {
        $plugin = $data['plugin'] ?? null;
        $is_active_callback = is_array($plugin) ? $plugin['is_active'] ?? null : null;

        if (is_callable($is_active_callback)) {
            return (bool) call_user_func($is_active_callback);
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
     *
     * Follows the WordPress Core Connectors API authentication contract: an
     * `authentication.method` of `none` never requires credentials; `api_key`
     * connectors are checked in the documented priority order (environment
     * variable, then PHP constant, then the database option), each defaulting
     * to the `{PROVIDER_ID}_API_KEY` / `connectors_ai_{id}_api_key` naming
     * convention unless the connector overrides it.
     *
     * @param string               $connector_id Connector ID.
     * @param array<string, mixed> $data Connector registry data.
     */
    private function has_authentication(string $connector_id, array $data): bool {
        $authentication = $data['authentication'] ?? null;

        if (!is_array($authentication)) {
            return false;
        }

        $method = is_string($authentication['method'] ?? null) ? $authentication['method'] : 'api_key';

        if (hash_equals('none', $method)) {
            return true;
        }

        if (!hash_equals('api_key', $method)) {
            return false;
        }

        return '' !== $this->resolve_api_key($connector_id, $authentication);
    }

    /**
     * Resolve the API key a WordPress Core Connector would use for a built-in AI
     * provider (e.g. `openai`), following the standard env var / PHP constant / DB
     * option naming convention.
     *
     * This works even when the Connectors API itself isn't available (e.g. WordPress
     * < 7.0): it simply reads the same well-known locations Core's Connectors screen
     * would read/write, so AWPT can reuse a key the site owner already configured
     * elsewhere instead of asking them to paste it in twice.
     */
    public function resolve_default_provider_api_key(string $provider_id): string {
        return $this->resolve_api_key($provider_id, ['method' => 'api_key']);
    }

    /**
     * Resolve a configured API key value, following the documented lookup order.
     *
     * @param string                  $connector_id Connector ID.
     * @param array<array-key, mixed> $authentication Connector authentication metadata.
     */
    private function resolve_api_key(string $connector_id, array $authentication): string {
        $default_name = strtoupper($connector_id) . '_API_KEY';

        $env_var_name = is_string($authentication['env_var_name'] ?? null) && '' !== $authentication['env_var_name']
            ? $authentication['env_var_name']
            : $default_name;

        $env_value = getenv($env_var_name);

        if (is_string($env_value) && '' !== trim($env_value)) {
            return trim($env_value);
        }

        $constant_name = is_string($authentication['constant_name'] ?? null) && '' !== $authentication['constant_name']
            ? $authentication['constant_name']
            : $default_name;

        if (defined($constant_name)) {
            $constant_value = constant($constant_name);

            if (is_string($constant_value) && '' !== trim($constant_value)) {
                return trim($constant_value);
            }
        }

        $setting_name = is_string($authentication['setting_name'] ?? null) && '' !== $authentication['setting_name']
            ? $authentication['setting_name']
            : 'connectors_ai_' . $connector_id . '_api_key';

        return trim((string) get_option($setting_name, ''));
    }

    /**
     * Extract the connector plugin file path from registry data.
     *
     * @param array<string, mixed> $data Connector registry data.
     */
    private function plugin_file_from_data(array $data): string {
        $plugin = $data['plugin'] ?? null;

        if (!is_array($plugin) || !is_string($plugin['file'] ?? null)) {
            return '';
        }

        return $plugin['file'];
    }
}
