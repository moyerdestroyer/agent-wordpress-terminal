<?php

/**
 * Filesystem knowledge label tests.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\FilesystemAccessPolicy;
use AWPT\Knowledge\FilesystemSourceFactory;

function test_filesystem_source_labels_use_theme_relative_paths(): void {
    $content = awpt_test_fs_policy_seed_tree();
    $root = $content . '/themes/open-theme';
    $relative = 'docs/Patterns/Documentation Page.md';
    $path = $root . '/' . $relative;
    $dir = dirname($path);

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($path, "# Documentation Page\n\nUse for long-form docs.\n");
    $source = new FilesystemSourceFactory()->from_file($path, $root, FilesystemAccessPolicy::ROOT_THEME);

    Assert::true(is_array($source), 'docs markdown under theme should become a source');
    Assert::same(
        'theme:docs/Patterns/Documentation Page.md',
        $source['label'] ?? null,
        'label should be theme-relative, not basename only',
    );
}

test_filesystem_source_labels_use_theme_relative_paths();
