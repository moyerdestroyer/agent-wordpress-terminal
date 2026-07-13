<?php

/**
 * awpt/read-themes ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns installed theme summaries for agent analysis.
 */
final class ReadThemes implements AbilityInterface {
    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-themes',
            'label' => __('Read Themes', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns installed WordPress themes and the active stylesheet.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => AbilitySchemas::empty_object_input(),
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
     * @param array<string, mixed> $input Ability input.
     */
    public function can_read(array $input): bool {
        return current_user_can('switch_themes') || current_user_can('edit_theme_options');
    }

    /**
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $active = get_stylesheet();
        $themes = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            $themes[] = [
                'stylesheet' => $stylesheet,
                'template' => $theme->get_template(),
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'description' => $theme->get('Description'),
                'active' => $stylesheet === $active,
            ];
        }

        return [
            'active_stylesheet' => $active,
            'active_template' => get_template(),
            'themes' => $themes,
        ];
    }
}
