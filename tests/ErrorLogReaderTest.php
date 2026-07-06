<?php

/**
 * Tests for AWPT\Support\Diagnostics\ErrorLogReader.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\Diagnostics\ErrorLogReader;

function test_error_log_reader(): void {
    if (!defined('WP_CONTENT_DIR')) {
        define('WP_CONTENT_DIR', sys_get_temp_dir());
    }

    $log_path = WP_CONTENT_DIR . '/debug.log';
    $backup_exists = file_exists($log_path);
    $backup = $backup_exists ? (string) file_get_contents($log_path) : null;

    file_put_contents($log_path, "line one\nPHP Fatal error: test failure\nline three\n");

    try {
        $reader = new ErrorLogReader();
        $result = $reader->read(10);

        Assert::true($result['exists'], 'debug.log should exist');
        Assert::true(
            in_array('PHP Fatal error: test failure', $result['lines'], true),
            'fatal line should be returned',
        );
    } finally {
        if (null === $backup) {
            if (file_exists($log_path)) {
                unlink($log_path);
            }
        } else {
            file_put_contents($log_path, $backup);
        }
    }
}

test_error_log_reader();
