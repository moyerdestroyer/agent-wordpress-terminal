<?php

/**
 * Applies staged theme switches.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Applies a staged installed-theme switch.
 */
final class ThemeSwitchActionApplier
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error
    {
        if (!current_user_can('switch_themes')) {
            return new \WP_Error(
                'awpt_cannot_switch_themes',
                __('You do not have permission to switch themes.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $stylesheet = sanitize_key((string) ($payload['stylesheet'] ?? ''));
        $theme = wp_get_theme($stylesheet);

        if ('' === $stylesheet || !$theme->exists()) {
            return new \WP_Error('awpt_theme_not_found', __('Installed theme not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        switch_theme($stylesheet);

        return [
            'stylesheet' => $stylesheet,
            'theme_name' => $theme->get('Name'),
        ];
    }
}
