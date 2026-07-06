<?php

/**
 * awpt/read-site-health ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\Diagnostics\SiteHealthReader;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns WordPress Site Health environment and test results.
 */
final class ReadSiteHealth {
    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-site-health',
            'label' => __('Read Site Health', 'agent-wordpress-terminal'),
            'description' => __(
                'Runs WordPress Site Health checks and returns environment details plus test results.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'scope' => [
                        'type' => 'string',
                        'enum' => ['summary', 'full', 'environment_only'],
                        'description' => __('How much Site Health data to return.', 'agent-wordpress-terminal'),
                    ],
                    'run_async' => [
                        'type' => 'boolean',
                        'description' => __(
                            'Run async Site Health tests (loopback, HTTPS, etc.).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'tests' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __('Optional list of specific test slugs to run.', 'agent-wordpress-terminal'),
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
        if (current_user_can('view_site_health_checks')) {
            return true;
        }

        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $scope = sanitize_key((string) ($input['scope'] ?? 'summary'));

        if (!in_array($scope, ['summary', 'full', 'environment_only'], true)) {
            $scope = 'summary';
        }

        $tests = is_array($input['tests'] ?? null) ? array_map('sanitize_key', $input['tests']) : [];

        return new SiteHealthReader()->read([
            'scope' => $scope,
            'run_async' => (bool) ($input['run_async'] ?? 'full' === $scope),
            'tests' => $tests,
        ]);
    }
}
