<?php

/**
 * awpt/read-plugins ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\Diagnostics\PluginInventory;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns installed plugin inventory for agent diagnosis.
 */
final class ReadPlugins implements AbilityInterface {
    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-plugins',
            'label' => __('Read Plugins', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns installed WordPress plugins with activation state for troubleshooting.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'active_only' => [
                        'type' => 'boolean',
                        'description' => __('When true, return only active plugins.', 'agent-wordpress-terminal'),
                    ],
                ],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_read(array $input): bool {
        return current_user_can('activate_plugins');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $active_only = (bool) ($input['active_only'] ?? false);

        return [
            'plugins' => new PluginInventory()->list($active_only),
        ];
    }
}
