<?php

/**
 * awpt/read-theme-json ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads and summarizes the active theme theme.json settings.
 */
final class ReadThemeJson {
    private const MAX_JSON_CHARS = 14_000;

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-theme-json',
            'label' => __('Read Theme JSON', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns a summary of the active theme theme.json (settings, styles, custom templates) when present.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'stylesheet' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional theme stylesheet. Defaults to the active theme.',
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
        return current_user_can('switch_themes') || current_user_can('edit_theme_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $stylesheet = sanitize_text_field((string) ($input['stylesheet'] ?? get_stylesheet()));
        $theme = wp_get_theme($stylesheet);

        if (!$theme->exists()) {
            return new \WP_Error('awpt_theme_not_found', __('Theme not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $path = trailingslashit($theme->get_stylesheet_directory()) . 'theme.json';

        if (!is_readable($path)) {
            return [
                'stylesheet' => $stylesheet,
                'theme_name' => $theme->get('Name'),
                'has_theme_json' => false,
                'path' => $path,
                'data' => null,
            ];
        }

        $raw = file_get_contents($path);

        if (!is_string($raw) || '' === trim($raw)) {
            return new \WP_Error('awpt_theme_json_unreadable', __(
                'Could not read theme.json.',
                'agent-wordpress-terminal',
            ));
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return new \WP_Error('awpt_theme_json_invalid', __(
                'theme.json is not valid JSON.',
                'agent-wordpress-terminal',
            ));
        }

        $summary = [
            'version' => $decoded['version'] ?? null,
            'settings' => $decoded['settings'] ?? null,
            'styles' => $decoded['styles'] ?? null,
            'customTemplates' => $decoded['customTemplates'] ?? null,
            'templateParts' => $decoded['templateParts'] ?? null,
        ];
        $encoded = wp_json_encode($summary);
        $truncated = false;

        if (is_string($encoded) && strlen($encoded) > self::MAX_JSON_CHARS) {
            $truncated = true;
            $summary = [
                'version' => $decoded['version'] ?? null,
                'settings_keys' => is_array($decoded['settings'] ?? null) ? array_keys($decoded['settings']) : [],
                'styles_keys' => is_array($decoded['styles'] ?? null) ? array_keys($decoded['styles']) : [],
                'customTemplates' => $decoded['customTemplates'] ?? null,
                'templateParts' => $decoded['templateParts'] ?? null,
                'note' => 'theme.json truncated; ask for specific settings keys if needed.',
            ];
        }

        return [
            'stylesheet' => $stylesheet,
            'theme_name' => $theme->get('Name'),
            'has_theme_json' => true,
            'path' => $path,
            'truncated' => $truncated,
            'data' => $summary,
        ];
    }
}
