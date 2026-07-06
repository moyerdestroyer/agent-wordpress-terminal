<?php

/**
 * Tests for AWPT\Support\Diagnostics\SiteHealthReader correlation helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\Diagnostics\SiteHealthCorrelator;

function test_site_health_reader_correlation(): void {
    $reader = new SiteHealthCorrelator();
    $tests = [
        [
            'slug' => 'loopback_requests',
            'label' => 'Loopback request',
            'status' => 'critical',
            'description' => 'fail',
            'actions' => '',
        ],
        [
            'slug' => 'php_version',
            'label' => 'PHP version',
            'status' => 'good',
            'description' => 'ok',
            'actions' => '',
        ],
    ];

    $relevant = $reader->correlate('js_error', $tests);
    Assert::same(1, count($relevant), 'js errors should correlate to loopback tests');
    Assert::same('loopback_requests', $relevant[0]['slug'] ?? '', 'loopback test should be selected');
}

test_site_health_reader_correlation();
