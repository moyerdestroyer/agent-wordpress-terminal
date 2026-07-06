<?php

/**
 * Tests for AWPT\Support\Diagnostics\ErrorPathAttributor.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\Diagnostics\ErrorPathAttributor;

function test_error_path_attributor(): void {
    $attributor = new ErrorPathAttributor();

    $result = $attributor->from_text("PHP Fatal error: Uncaught Error: foo()\n"
    . 'in /var/www/html/wp-content/plugins/acme-widgets/acme.php on line 12');

    Assert::same('php_fatal', $result['error_type'], 'fatal errors should be classified');
    Assert::same(1, count($result['suspects']), 'plugin path should produce one suspect');

    if ([] !== $result['suspects']) {
        Assert::same('plugin', $result['suspects'][0]['kind'], 'suspect kind should be plugin');
        Assert::same('acme-widgets', $result['suspects'][0]['slug'], 'plugin slug should be parsed');
    }

    $theme = $attributor->from_text(
        'PHP Warning: something broke in /wp-content/themes/twentytwentyfive/functions.php',
    );
    Assert::same('php_warning', $theme['error_type'], 'warnings should be classified');
    Assert::same('twentytwentyfive', $theme['suspects'][0]['slug'] ?? '', 'theme slug should be parsed');

    $js = $attributor->from_text('ReferenceError: foo is not defined at https://example.com/app.js:4:9');
    Assert::same('js_error', $js['error_type'], 'js errors should be classified');
}

test_error_path_attributor();
