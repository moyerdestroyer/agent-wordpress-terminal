<?php

/**
 * Bootstrap-free test runner for AWPT.
 *
 * AWPT has no PHPUnit/WordPress test-suite dependency yet, so this runner wires up the
 * minimal WordPress stubs the unit-testable classes need (see wp-stubs.php) and executes
 * each *Test.php file directly. Run with: php tests/run.php
 *
 * @package AWPT
 */

declare(strict_types=1);

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/Assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$test_files = glob(__DIR__ . '/*Test.php');

if (false === $test_files) {
    fwrite(STDERR, "Could not locate test files.\n");

    exit(1);
}

sort($test_files);

foreach ($test_files as $test_file) {
    require $test_file;
}

$passed = Assert::passed();
$failures = Assert::failures();

printf("%d assertion(s) passed.\n", $passed);

if ([] === $failures) {
    echo "OK\n";

    exit(0);
}

printf("%d assertion(s) FAILED:\n", count($failures));

foreach ($failures as $failure) {
    echo ' - ' . $failure . "\n";
}

exit(1);
