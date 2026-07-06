<?php

/**
 * Applies staged plugin deactivations.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Deactivates a plugin from a staged action payload.
 */
final class PluginDeactivateActionApplier {
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error {
        if (!current_user_can('activate_plugins')) {
            return new \WP_Error(
                'awpt_cannot_deactivate_plugins',
                __('You do not have permission to deactivate plugins.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $plugin_file = sanitize_text_field((string) ($payload['plugin_file'] ?? ''));

        if ('' === $plugin_file || str_contains($plugin_file, 'agent-wordpress-terminal/')) {
            return new \WP_Error(
                'awpt_plugin_protected',
                __('This plugin cannot be deactivated through AWPT.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!array_key_exists($plugin_file, get_plugins())) {
            return new \WP_Error(
                'awpt_plugin_not_found',
                __('Installed plugin not found.', 'agent-wordpress-terminal'),
                [
                    'status' => 404,
                ],
            );
        }

        deactivate_plugins($plugin_file);

        return [
            'plugin_file' => $plugin_file,
            'plugin_name' => (string) ($payload['plugin_name'] ?? ''),
            'plugin_slug' => (string) ($payload['plugin_slug'] ?? ''),
        ];
    }
}
