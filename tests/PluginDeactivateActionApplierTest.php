<?php

/**
 * Tests for AWPT\Abilities\ActionAppliers\PluginDeactivateActionApplier.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\ActionAppliers\PluginDeactivateActionApplier;

function test_plugin_deactivate_action_applier(): void {
    $applier = new PluginDeactivateActionApplier();

    awpt_test_reset_state();
    $GLOBALS['awpt_test_plugins'] = [
        'acme-widgets/acme.php' => ['Name' => 'Acme Widgets', 'Version' => '1.0'],
    ];
    $GLOBALS['awpt_test_active_plugins'] = ['acme-widgets/acme.php'];
    $GLOBALS['awpt_test_current_user_can'] = static fn(string $cap): bool => 'activate_plugins' === $cap;

    $result = $applier->apply([
        'plugin_file' => 'acme-widgets/acme.php',
        'plugin_name' => 'Acme Widgets',
        'plugin_slug' => 'acme-widgets',
    ]);

    Assert::false(is_wp_error($result), 'valid plugin deactivate should succeed');

    if (!is_wp_error($result)) {
        Assert::same('acme-widgets/acme.php', $result['plugin_file'], 'plugin file should be returned');
        Assert::same(
            ['acme-widgets/acme.php'],
            $GLOBALS['awpt_test_deactivated_plugins'],
            'plugin should be deactivated',
        );
    }

    awpt_test_reset_state();
    $GLOBALS['awpt_test_plugins'] = [
        'agent-wordpress-terminal/agent-wordpress-terminal.php' => ['Name' => 'AWPT', 'Version' => '0.1'],
    ];
    $GLOBALS['awpt_test_current_user_can'] = static fn(string $cap): bool => 'activate_plugins' === $cap;

    $blocked = $applier->apply([
        'plugin_file' => 'agent-wordpress-terminal/agent-wordpress-terminal.php',
    ]);
    Assert::true(is_wp_error($blocked), 'AWPT self-deactivation should be blocked');
}

test_plugin_deactivate_action_applier();
