<?php

/**
 * Tests for AWPT\Support\Diagnostics\RemediationHintBuilder.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\Diagnostics\ErrorPathAttributor;
use AWPT\Support\Diagnostics\RemediationHintBuilder;

/**
 * @return list<string>
 */
function awpt_hint_types(array $hints): array {
    return array_values(array_map(static fn(array $hint): string => (string) ($hint['type'] ?? ''), $hints));
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function awpt_remediation_context(string $error_text, array $overrides = []): array {
    $attribution = new ErrorPathAttributor()->from_text($error_text);

    return array_merge([
        'error_text' => $error_text,
        'error_type' => $attribution['error_type'],
        'suspects' => $attribution['suspects'],
        'evidence' => $attribution['evidence'],
        'attempted_action' => '',
        'url' => '',
        'incident_kind' => '',
        'environment' => ['php_memory_limit' => '128M'],
        'relevant_tests' => [],
        'url_probe' => null,
    ], $overrides);
}

function test_remediation_hint_builder(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_plugins'] = [
        'acme-widgets/acme.php' => ['Name' => 'Acme Widgets', 'Version' => '1.0'],
    ];

    $builder = new RemediationHintBuilder();

    $fatal_plugin = $builder->build(awpt_remediation_context("PHP Fatal error: Uncaught Error: boom\n"
    . 'in /var/www/html/wp-content/plugins/acme-widgets/acme.php on line 9', [
        'relevant_tests' => [
            ['slug' => 'php_version', 'label' => 'PHP version', 'status' => 'recommended'],
        ],
    ]));
    $fatal_types = awpt_hint_types($fatal_plugin);
    Assert::true(in_array('deactivate_plugin', $fatal_types, true), 'fatal plugin error should suggest deactivate');
    Assert::true(
        in_array('check_site_health', $fatal_types, true),
        'fatal plugin error should suggest site health when tests present',
    );

    $deactivate = array_values(array_filter(
        $fatal_plugin,
        static fn(array $hint): bool => 'deactivate_plugin' === ($hint['type'] ?? ''),
    ))[0] ?? [];
    Assert::same('unambiguous', $deactivate['confidence'] ?? '', 'deactivate hint should be unambiguous');
    Assert::same('acme-widgets/acme.php', $deactivate['plugin_file'] ?? '', 'plugin file should resolve');

    $warning_plugin = $builder->build(awpt_remediation_context(
        'PHP Warning: something broke in /wp-content/plugins/acme-widgets/acme.php on line 4',
    ));
    Assert::false(
        in_array('deactivate_plugin', awpt_hint_types($warning_plugin), true),
        'plugin warning should not suggest deactivate',
    );

    $theme_fatal = $builder->build(awpt_remediation_context("PHP Fatal error: Uncaught Error: boom\n"
    . 'in /var/www/html/wp-content/themes/twentytwentyfive/functions.php on line 2'));
    $theme_types = awpt_hint_types($theme_fatal);
    Assert::true(in_array('switch_theme', $theme_types, true), 'theme fatal should suggest switch_theme');
    Assert::false(in_array('deactivate_plugin', $theme_types, true), 'theme fatal should not suggest deactivate');

    $memory = $builder->build(awpt_remediation_context(
        'PHP Fatal error: Allowed memory size of 134217728 bytes exhausted in /wp-includes/class-wp-query.php',
    ));
    Assert::true(
        in_array('increase_memory', awpt_hint_types($memory), true),
        'memory exhausted should suggest increase_memory',
    );

    $content_failure = $builder->build(awpt_remediation_context('Apply failed: invalid block markup', [
        'attempted_action' => 'content_update',
    ]));
    $content_types = awpt_hint_types($content_failure);
    Assert::true(in_array('retry_action', $content_types, true), 'content apply failure should suggest retry');
    Assert::true(in_array('fix_content', $content_types, true), 'content apply failure should suggest fix_content');

    $preview_failure = $builder->build(awpt_remediation_context('Preview render failed with HTTP 500', [
        'attempted_action' => 'preview',
        'incident_kind' => 'preview_failure',
        'url' => 'https://example.com/?p=42',
    ]));
    $preview_types = awpt_hint_types($preview_failure);
    Assert::true(in_array('probe_url', $preview_types, true), 'preview failure should suggest probe_url');
    Assert::true(in_array('retry_action', $preview_types, true), 'preview failure should suggest retry');

    $js_error = $builder->build(awpt_remediation_context('ReferenceError: foo is not defined at https://example.com/wp-admin/admin.php:12:4', [
        'incident_kind' => 'js',
    ]));
    $js_types = awpt_hint_types($js_error);
    Assert::true(in_array('probe_url', $js_types, true), 'js incident should suggest probe_url');
    Assert::true(in_array('check_site_health', $js_types, true), 'js incident should suggest site health');
    Assert::false(in_array('deactivate_plugin', $js_types, true), 'js incident should not suggest deactivate');
}

test_remediation_hint_builder();
