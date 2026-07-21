<?php

/**
 * AWPT abilities registry health checks.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Verifies that AWPT abilities registered successfully.
 */
final class AbilitiesHealth {
    /**
     * Ability names AWPT expects to register.
     */
    private const EXPECTED_ABILITIES = [
        'awpt/read-content',
        'awpt/read-themes',
        'awpt/read-theme-json',
        'awpt/read-block-tree',
        'awpt/get-block',
        'awpt/list-blocks',
        'awpt/render-block',
        'awpt/analyze-page',
        'awpt/preview-post',
        'awpt/search-content',
        'awpt/list-content',
        'awpt/list-templates',
        'awpt/read-template',
        'awpt/list-patterns',
        'awpt/read-pattern',
        'awpt/read-global-styles',
        'awpt/search-knowledge',
        'awpt/read-knowledge',
        'awpt/propose-content-update',
        'awpt/propose-block-attrs-update',
        'awpt/propose-block-insert',
        'awpt/propose-block-remove',
        'awpt/propose-pattern-insert',
        'awpt/propose-template-update',
        'awpt/propose-global-styles-update',
        'awpt/propose-new-post',
        'awpt/propose-site-settings-update',
        'awpt/propose-theme-switch',
        'awpt/propose-plugin-deactivate',
        'awpt/apply-action',
        'awpt/read-error-log',
        'awpt/read-plugins',
        'awpt/read-site-health',
        'awpt/probe-url',
        'awpt/diagnose-error',
    ];

    /**
     * Whether the Abilities API functions AWPT needs are present.
     */
    public static function api_functions_available(): bool {
        return (
            function_exists('wp_register_ability')
            && function_exists('wp_register_ability_category')
            && function_exists('wp_get_abilities')
            && function_exists('wp_get_ability')
        );
    }

    /**
     * Whether AWPT abilities registered successfully.
     */
    public static function is_awpt_registry_healthy(): bool {
        if (!self::api_functions_available() || !function_exists('wp_has_ability')) {
            return false;
        }

        return wp_has_ability('awpt/search-content');
    }

    /**
     * Return registration health details for REST/UI consumers.
     *
     * @return array<string, mixed>
     */
    public static function registration_status(): array {
        $registered = self::registered_awpt_abilities();

        return [
            'healthy' => self::is_awpt_registry_healthy(),
            'registered_count' => count($registered),
            'expected_count' => count(self::EXPECTED_ABILITIES),
            'registered' => $registered,
            'missing' => array_values(array_diff(self::EXPECTED_ABILITIES, $registered)),
        ];
    }

    /**
     * Return registered AWPT ability names.
     *
     * @return list<string>
     */
    public static function registered_awpt_abilities(): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $registered = [];

        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();

            if (str_starts_with($name, 'awpt/')) {
                $registered[] = $name;
            }
        }

        sort($registered);

        return $registered;
    }
}
