<?php

/**
 * Runtime environment checks.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reports WordPress, PHP, and Abilities API readiness.
 */
final class Environment
{
    /**
     * Return the current environment status for REST/UI consumers.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $wordpress_version = self::wordpress_version();
        $php_supported = version_compare(PHP_VERSION, AWPT_MINIMUM_PHP_VERSION, '>=');
        $wordpress_supported = version_compare($wordpress_version, AWPT_MINIMUM_WP_VERSION, '>=');
        $abilities_available = self::abilities_available();
        $registration = AbilitiesHealth::registration_status();

        return [
            'php' => [
                'version' => PHP_VERSION,
                'minimum' => AWPT_MINIMUM_PHP_VERSION,
                'supported' => $php_supported,
            ],
            'wordpress' => [
                'version' => $wordpress_version,
                'minimum' => AWPT_MINIMUM_WP_VERSION,
                'supported' => $wordpress_supported,
            ],
            'abilities' => [
                'available' => $abilities_available,
                'label' => $abilities_available
                    ? __('Available', 'agent-wordpress-terminal')
                    : __('Unavailable', 'agent-wordpress-terminal'),
                'registration' => $registration,
            ],
            'supported' => $php_supported && $wordpress_supported,
            'warnings' => self::warnings(),
        ];
    }

    /**
     * Whether the plugin can boot safely.
     */
    public static function meets_minimum_requirements(): bool
    {
        return [] === self::requirement_errors();
    }

    /**
     * Return fatal requirement errors.
     *
     * @return list<string>
     */
    public static function requirement_errors(): array
    {
        $errors = [];

        if (version_compare(PHP_VERSION, AWPT_MINIMUM_PHP_VERSION, '<')) {
            $errors[] = sprintf(
                /* translators: 1: required PHP version, 2: current PHP version. */
                __(
                    'Agent WordPress Terminal requires PHP %1$s or newer. Current PHP version: %2$s.',
                    'agent-wordpress-terminal',
                ),
                AWPT_MINIMUM_PHP_VERSION,
                PHP_VERSION,
            );
        }

        $wordpress_version = self::wordpress_version();

        if (version_compare($wordpress_version, AWPT_MINIMUM_WP_VERSION, '<')) {
            $errors[] = sprintf(
                /* translators: 1: required WordPress version, 2: current WordPress version. */
                __(
                    'Agent WordPress Terminal requires WordPress %1$s or newer. Current WordPress version: %2$s.',
                    'agent-wordpress-terminal',
                ),
                AWPT_MINIMUM_WP_VERSION,
                $wordpress_version,
            );
        }

        return $errors;
    }

    /**
     * Render admin warnings for unsupported or degraded environments.
     */
    public static function render_admin_notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        foreach (self::requirement_errors() as $error) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($error));
        }

        if ([] !== self::requirement_errors()) {
            return;
        }

        foreach (self::warnings() as $warning) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html($warning));
        }
    }

    /**
     * Return non-fatal environment warnings.
     *
     * @return list<string>
     */
    private static function warnings(): array
    {
        $warnings = [];

        if (!AbilitiesHealth::api_functions_available()) {
            $warnings[] = __(
                'Agent WordPress Terminal is active, but the WordPress Abilities API is unavailable. Plugin tools will not register until the Abilities API functions are present.',
                'agent-wordpress-terminal',
            );

            return $warnings;
        }

        if (!AbilitiesHealth::is_awpt_registry_healthy()) {
            $warnings[] = __(
                'AWPT abilities failed to register. Ensure ability categories register on wp_abilities_api_categories_init and abilities on wp_abilities_api_init. Check debug.log for Abilities API notices.',
                'agent-wordpress-terminal',
            );
        }

        return $warnings;
    }

    /**
     * Whether WordPress exposes the Abilities API and AWPT abilities registered.
     */
    private static function abilities_available(): bool
    {
        return AbilitiesHealth::api_functions_available() && AbilitiesHealth::is_awpt_registry_healthy();
    }

    /**
     * Return the current WordPress version.
     */
    private static function wordpress_version(): string
    {
        global $wp_version;

        if (is_string($wp_version) && '' !== $wp_version) {
            return $wp_version;
        }

        if (function_exists('get_bloginfo')) {
            return get_bloginfo('version');
        }

        return '0';
    }
}
