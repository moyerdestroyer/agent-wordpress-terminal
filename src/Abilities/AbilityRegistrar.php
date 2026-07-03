<?php

/**
 * Shared ability registration helper.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

defined('ABSPATH') || exit();

/**
 * Registers AWPT abilities with consistent schema shape.
 */
final class AbilityRegistrar
{
    /**
     * @param array{
     *     name: string,
     *     label: string,
     *     description: string,
     *     input_schema: array<string, mixed>,
     *     output_schema: array<string, mixed>,
     *     permission_callback: callable(array<string, mixed>): bool,
     *     execute_callback: callable(array<string, mixed>): (array<string, mixed>|\WP_Error),
     *     annotations?: array<string, bool>
     * } $config
     */
    public static function register(array $config): void
    {
        $annotations = $config['annotations'] ?? [
            'readonly' => true,
            'destructive' => false,
        ];

        wp_register_ability($config['name'], [
            'label' => $config['label'],
            'description' => $config['description'],
            'category' => 'awpt',
            'input_schema' => $config['input_schema'],
            'output_schema' => $config['output_schema'],
            'permission_callback' => $config['permission_callback'],
            'execute_callback' => $config['execute_callback'],
            'meta' => [
                'annotations' => $annotations,
            ],
        ]);
    }
}
