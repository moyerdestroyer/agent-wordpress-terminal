<?php

/**
 * Applies staged Additional CSS updates.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Writes Customizer Additional CSS via wp_update_custom_css_post().
 */
final class CustomCssActionApplier {
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error {
        if (!current_user_can('edit_css') && !current_user_can('edit_theme_options')) {
            return new \WP_Error(
                'awpt_cannot_edit_css',
                __('You do not have permission to edit Additional CSS.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        if (!function_exists('wp_update_custom_css_post') || !function_exists('wp_get_custom_css')) {
            return new \WP_Error(
                'awpt_custom_css_unavailable',
                __('This WordPress install does not expose Additional CSS APIs.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        $stylesheet = sanitize_text_field((string) ($payload['stylesheet'] ?? get_stylesheet()));
        $css = (string) ($payload['css'] ?? '');
        $original = (string) ($payload['original_css'] ?? '');
        $live = wp_get_custom_css($stylesheet);

        if ('' !== $original && $live !== $original && $live !== $css) {
            return new \WP_Error(
                'awpt_action_conflict',
                __(
                    'Additional CSS changed after this proposal was staged. Review and restage before applying.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409, 'field' => 'custom_css'],
            );
        }

        $result = wp_update_custom_css_post($css, ['stylesheet' => $stylesheet]);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'stylesheet' => $stylesheet,
            'css_post_id' => (int) $result,
            'bytes' => strlen($css),
        ];
    }
}
