<?php

/**
 * awpt/read-error-log ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\Diagnostics\ErrorLogReader;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns recent PHP error log lines for agent diagnosis.
 */
final class ReadErrorLog implements AbilityInterface {
    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-error-log',
            'label' => __('Read Error Log', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns a tail slice of wp-content/debug.log or the PHP error log for diagnosis.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'max_lines' => [
                        'type' => 'integer',
                        'description' => __(
                            'Maximum lines to return (default 100, max 200).',
                            'agent-wordpress-terminal',
                        ),
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
        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $max_lines = (int) ($input['max_lines'] ?? 100);

        return new ErrorLogReader()->read($max_lines);
    }
}
