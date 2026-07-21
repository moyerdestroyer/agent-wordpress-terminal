<?php

/**
 * Tests for open FilesystemAccessPolicy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\FilesystemAccessPolicy;
use AWPT\Knowledge\FilesystemSourceFactory;

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

function test_prunes_dependency_and_generated_directories(): void {
    $content = awpt_test_fs_policy_seed_tree();
    $root = $content . '/themes/open-theme';
    $policy = new FilesystemAccessPolicy();

    foreach (['node_modules', 'vendor', 'build', 'dist', 'coverage', 'cache', '.git'] as $directory) {
        $path = $root . '/' . $directory;

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        Assert::false(
            $policy->can_traverse_directory($path, $root),
            sprintf('%s should be pruned before recursive traversal', $directory),
        );
    }
}

function test_theme_policy_keeps_design_context_only(): void {
    $content = awpt_test_fs_policy_seed_tree();
    $root = $content . '/themes/open-theme';
    $files = [
        'theme.json' => true,
        'style.css' => true,
        'styles/brand.json' => true,
        'templates/page.html' => true,
        'parts/header.html' => true,
        'patterns/call-to-action.php' => false,
        'patterns/call-to-action.html' => true,
        'patterns/call-to-action.json' => true,
        'README.md' => true,
        'assets/brand.css' => true,
        'package-lock.json' => false,
        'package.json' => false,
        'assets/editor.js' => false,
        'node_modules/package/readme.md' => false,
    ];
    $policy = new FilesystemAccessPolicy();

    foreach ($files as $relative => $expected) {
        $path = $root . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, 'Knowledge fixture');
        Assert::same(
            $expected,
            $policy->can_read_file($path, $root, FilesystemAccessPolicy::ROOT_THEME),
            sprintf('theme relevance for %s', $relative),
        );
    }
}

function test_document_roots_reject_code_and_unknown_files(): void {
    $content = awpt_test_fs_policy_seed_tree();
    $root = $content . '/uploads/docs';
    $files = [
        'guide.pdf' => true,
        'brand.md' => true,
        'data.json' => true,
        'notes.txt' => true,
        'bundle.js' => false,
        'source.ts' => false,
        'package.lock' => false,
        'binary.unknown' => false,
    ];
    $policy = new FilesystemAccessPolicy();

    foreach ($files as $relative => $expected) {
        $path = $root . '/' . $relative;
        file_put_contents($path, 'Knowledge fixture');
        Assert::same(
            $expected,
            $policy->can_read_file($path, $root, FilesystemAccessPolicy::ROOT_UPLOADS),
            sprintf('document relevance for %s', $relative),
        );
    }
}

function test_theme_template_block_structure_is_preserved(): void {
    $content = awpt_test_fs_policy_seed_tree();
    $root = $content . '/themes/open-theme';
    $templates = $root . '/templates';

    if (!is_dir($templates)) {
        mkdir($templates, 0777, true);
    }

    $path = $templates . '/single.html';
    file_put_contents($path, '<!-- wp:template-part {"slug":"header"} /--><!-- wp:post-content /-->');
    $source = new FilesystemSourceFactory()->from_file($path, $root, FilesystemAccessPolicy::ROOT_THEME);

    Assert::true(is_array($source), 'a block theme template should produce a Knowledge source');
    Assert::true(
        str_contains((string) ($source['content'] ?? ''), 'WordPress block template-part'),
        'block comments should become searchable template structure',
    );
    Assert::true(
        str_contains((string) ($source['content'] ?? ''), 'WordPress block post-content'),
        'self-closing block names should be retained',
    );
}

test_open_roots_under_wp_content();
test_blocks_php_and_allows_markdown();
test_prunes_dependency_and_generated_directories();
test_theme_policy_keeps_design_context_only();
test_document_roots_reject_code_and_unknown_files();
test_theme_template_block_structure_is_preserved();
