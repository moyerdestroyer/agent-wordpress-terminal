<?php

/**
 * Installed WordPress plugin inventory for diagnostics.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists installed plugins with activation state.
 */
final class PluginInventory {
    /**
     * @return list<array{slug: string, name: string, version: string, file: string, active: bool, network_active: bool}>
     */
    public function list(bool $active_only = false): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = [];
        $all = get_plugins();

        foreach ($all as $plugin_file => $data) {
            $file = $plugin_file;
            $slug = dirname($file);
            $slug = '.' === $slug ? basename($file, '.php') : $slug;
            $active = $this->is_active($file);

            if ($active_only && !$active) {
                continue;
            }

            $plugins[] = [
                'slug' => $slug,
                'name' => $data['Name'] ?? $slug,
                'version' => $data['Version'] ?? '',
                'file' => $file,
                'active' => $active,
                'network_active' => $this->is_network_active($file),
            ];
        }

        usort($plugins, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $plugins;
    }

    /**
     * Resolve a plugin file path from a suspect slug.
     */
    public function file_for_slug(string $slug): ?string {
        foreach ($this->list() as $plugin) {
            if ($plugin['slug'] === $slug) {
                return $plugin['file'];
            }
        }

        return null;
    }

    private function is_active(string $file): bool {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($file);
    }

    private function is_network_active(string $file): bool {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_multisite() && is_plugin_active_for_network($file);
    }
}
