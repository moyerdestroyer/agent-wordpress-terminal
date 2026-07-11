<?php

/**
 * Filesystem access policy for Knowledge ingestion.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Validates read-only document roots and files.
 *
 * Policy is intentionally open under wp-content (themes, plugins, uploads, custom
 * roots). Only obviously executable / secret extensions are refused as text.
 */
final class FilesystemAccessPolicy {
    public const DEFAULT_MAX_FILE_SIZE = 2_097_152;

    /**
     * Extensions preferred for text-like indexing.
     *
     * @var list<string>
     */
    private const TEXT_EXTENSIONS = [
        'txt',
        'md',
        'markdown',
        'csv',
        'json',
        'html',
        'htm',
        'xml',
        'yml',
        'yaml',
        'css',
        'scss',
        'js',
        'ts',
        'tsx',
        'jsx',
        'twig',
        'liquid',
        'svg',
        'pdf',
    ];

    /**
     * Extensions that are never indexed as text.
     *
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'sh',
        'bash',
        'zsh',
        'env',
        'sql',
        'sqlite',
        'db',
        'exe',
        'bin',
        'dll',
        'so',
        'zip',
        'gz',
        'tar',
        'rar',
        '7z',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'ico',
        'mp4',
        'mp3',
        'wav',
        'woff',
        'woff2',
        'ttf',
        'eot',
    ];

    public function max_file_size(): int {
        $value = (int) get_option('awpt_knowledge_max_file_size', self::DEFAULT_MAX_FILE_SIZE);

        return max(1024, min($value, 20_971_520));
    }

    public function is_allowed_root(string $root): bool {
        $content_dir = realpath(WP_CONTENT_DIR);

        if (!is_string($content_dir)) {
            return false;
        }

        $root = rtrim($root, '/\\');

        return str_starts_with($root, $content_dir);
    }

    public function can_read_file(string $path, string $root): bool {
        $real = realpath($path);
        $root_real = realpath($root);

        if (!is_string($real) || !is_string($root_real)) {
            return false;
        }

        if (!str_starts_with($real, trailingslashit($root_real)) && $real !== $root_real) {
            return false;
        }

        // Skip hidden path segments like .git, .env directories.
        if (preg_match('~/\.~', $real)) {
            return false;
        }

        return $this->extension_is_readable($real) && $this->size_is_readable($real);
    }

    private function extension_is_readable(string $path): bool {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ('' === $extension) {
            return true;
        }

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return false;
        }

        // Prefer known text extensions; still allow unknown non-blocked types when open.
        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            return true;
        }

        return !in_array($extension, self::BLOCKED_EXTENSIONS, true);
    }

    private function size_is_readable(string $path): bool {
        $size = filesize($path);

        return is_int($size) && $size > 0 && $size <= $this->max_file_size() && is_readable($path);
    }
}
