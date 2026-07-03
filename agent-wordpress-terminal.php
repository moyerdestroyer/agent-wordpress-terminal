<?php

/**
 * Agent WordPress Terminal
 *
 * @package AWPT
 *
 * @wordpress-plugin
 * Plugin Name:       Agent WordPress Terminal
 * Plugin URI:        https://github.com/awpt/agent-wordpress-terminal
 * Description:       A WordPress-native terminal for agent-assisted site work.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            AWPT Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       agent-wordpress-terminal
 */

declare(strict_types=1);

namespace AWPT;

defined('ABSPATH') || exit();

/**
 * Define plugin constants.
 */
function constants(): void
{
    if (!defined('AWPT_PLUGIN_FILE')) {
        define('AWPT_PLUGIN_FILE', __FILE__);
    }

    if (!defined('AWPT_VERSION')) {
        define('AWPT_VERSION', '0.1.0');
    }

    if (!defined('AWPT_PLUGIN_DIR')) {
        define('AWPT_PLUGIN_DIR', plugin_dir_path(AWPT_PLUGIN_FILE));
    }

    if (!defined('AWPT_PLUGIN_URL')) {
        define('AWPT_PLUGIN_URL', plugin_dir_url(AWPT_PLUGIN_FILE));
    }

    if (!defined('AWPT_REST_NAMESPACE')) {
        define('AWPT_REST_NAMESPACE', 'awpt/v1');
    }

    if (!defined('AWPT_MINIMUM_WP_VERSION')) {
        define('AWPT_MINIMUM_WP_VERSION', '6.9');
    }

    if (!defined('AWPT_PREFERRED_WP_VERSION')) {
        define('AWPT_PREFERRED_WP_VERSION', '7.1');
    }

    if (!defined('AWPT_MINIMUM_PHP_VERSION')) {
        define('AWPT_MINIMUM_PHP_VERSION', '8.2');
    }
}

constants();

$awpt_autoloader = AWPT_PLUGIN_DIR . 'vendor/autoload.php';

if (!file_exists($awpt_autoloader)) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__(
            'Agent WordPress Terminal requires Composer dependencies. Run composer install in the plugin directory.',
            'agent-wordpress-terminal',
        ));
    });

    return;
}

require_once $awpt_autoloader;

/**
 * Plugin activation hook.
 */
function activate(): void
{
    $errors = Support\Environment::requirement_errors();

    if ([] !== $errors) {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins(plugin_basename(AWPT_PLUGIN_FILE));

        wp_die(
            esc_html(implode(' ', $errors)),
            esc_html__('Agent WordPress Terminal requirements not met', 'agent-wordpress-terminal'),
            ['back_link' => true],
        );
    }

    Database\Installer::activate();
}

if (!Support\Environment::meets_minimum_requirements()) {
    add_action('admin_notices', [Support\Environment::class, 'render_admin_notices']);

    return;
}

register_activation_hook(AWPT_PLUGIN_FILE, __NAMESPACE__ . '\\activate');
register_deactivation_hook(AWPT_PLUGIN_FILE, [Database\Installer::class, 'deactivate']);

Plugin::instance()->boot();
