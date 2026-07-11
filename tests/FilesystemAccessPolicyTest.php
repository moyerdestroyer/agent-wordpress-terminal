<?php

/**
 * Tests for open FilesystemAccessPolicy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\FilesystemAccessPolicy;

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/awpt-content-test-' . getmypid());
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $path): string {
        return rtrim($path, '/\\') . '/';
    }
}

function awpt_test_fs_policy_seed_tree(): string {
    $content = WP_CONTENT_DIR;

    if (!is_dir($content)) {
        mkdir($content, 0777, true);
    }

    foreach (['themes/open-theme', 'plugins/open-plugin', 'uploads/docs'] as $relative) {
        $dir = $content . '/' . $relative;

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    return $content;
}

function test_open_roots_under_wp_content(): void {
    $content = awpt_test_fs_policy_seed_tree();
    $policy = new FilesystemAccessPolicy();

    $theme_real = realpath($content . '/themes/open-theme');
    $plugin_real = realpath($content . '/plugins/open-plugin');

    Assert::true(is_string($theme_real) && $policy->is_allowed_root($theme_real), 'theme root should be open');
    Assert::true(is_string($plugin_real) && $policy->is_allowed_root($plugin_real), 'plugin root should be open');
}

function test_blocks_php_and_allows_markdown(): void {
    $content = awpt_test_fs_policy_seed_tree();
    $root = $content . '/uploads/docs';
    $md = $root . '/readme.md';
    $php = $root . '/evil.php';

    file_put_contents($md, "# Hello\n\nDocs for the agent.\n");
    file_put_contents($php, "<?php echo 'no';\n");

    $policy = new FilesystemAccessPolicy();
    $root_real = (string) realpath($root);

    Assert::true($policy->can_read_file($md, $root_real), 'markdown should be readable');
    Assert::false($policy->can_read_file($php, $root_real), 'php should be blocked');
}

test_open_roots_under_wp_content();
test_blocks_php_and_allows_markdown();
