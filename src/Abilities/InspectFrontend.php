<?php

/**
 * awpt/inspect-frontend ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\Diagnostics\FrontendInspector;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Inspects a same-site frontend URL for layout/CSS diagnosis.
 */
final class InspectFrontend implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/inspect-frontend',
            'label' => __('Inspect Frontend', 'agent-wordpress-terminal'),
            'description' => __(
                'Fetches a same-site page and returns title, stylesheets, a class inventory from the HTML, layout signals, an HTML snippet, and recommended next tools. Use when the editor and live page look different.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => __(
                            'Absolute same-site URL (or path resolved against home_url).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => __('Optional post ID; used when url is empty.', 'agent-wordpress-terminal'),
                    ],
                    'selector' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional class or token from the page to center the HTML snippet. Prefer tokens from inventory or prior tool evidence.',
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
        unset($input);

        return current_user_can('edit_theme_options') || current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $url = esc_url_raw((string) ($input['url'] ?? ''));
        $post_id = (int) ($input['post_id'] ?? 0);

        if ('' === $url && $post_id > 0) {
            $permalink = get_permalink($post_id);
            $url = is_string($permalink) ? esc_url_raw($permalink) : '';
        }

        if ('' === $url && str_starts_with((string) ($input['url'] ?? ''), '/')) {
            $url = esc_url_raw(home_url((string) $input['url']));
        }

        if ('' === $url) {
            return new \WP_Error(
                'awpt_invalid_url',
                __('Provide a same-site url or post_id.', 'agent-wordpress-terminal'),
                [
                    'status' => 400,
                ],
            );
        }

        return new FrontendInspector()->inspect($url, sanitize_text_field((string) ($input['selector'] ?? '')));
    }
}
