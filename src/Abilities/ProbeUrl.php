<?php

/**
 * awpt/probe-url ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\Diagnostics\UrlProbe;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Server-side same-site URL probe for rendered errors.
 */
final class ProbeUrl implements AbilityInterface {
    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/probe-url',
            'label' => __('Probe URL', 'agent-wordpress-terminal'),
            'description' => __(
                'Fetches a same-site URL and extracts PHP or critical error snippets from the response.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => __('Absolute URL on this WordPress site.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['url'],
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
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $url = esc_url_raw((string) ($input['url'] ?? ''));

        if ('' === $url) {
            return new \WP_Error('awpt_invalid_url', __('A valid URL is required.', 'agent-wordpress-terminal'));
        }

        return new UrlProbe()->probe($url);
    }
}
