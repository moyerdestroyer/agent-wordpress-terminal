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
 */
final class FilesystemAccessPolicy {
    public const DEFAULT_MAX_FILE_SIZE = 2_097_152;

    /**
     * Extensions that may be read as text in v1.
     *
     * @var list<string>
     */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'json', 'html', 'htm', 'xml'];

    /**
     * Extensions that are always blocked.
     *
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'js',
        'mjs',
        'cjs',
        'ts',
        'tsx',
        'jsx',
        'css',
        'scss',
        'sh',
        'bash',
        'zsh',
        'sql',
        'env',
        'ini',
    ];

    public function max_file_size(): int {
        $value = (int) get_option('awpt_knowledge_max_file_size', self::DEFAULT_MAX_FILE_SIZE);

        return max(1024, min($value, 10_485_760));
    }

    public function is_allowed_root(string $root): bool {
        $content_dir = realpath(WP_CONTENT_DIR);

        if (!is_string($content_dir) || !str_starts_with($root, $content_dir)) {
            return false;
        }

        return !preg_match('~/(plugins|themes|mu-plugins)(/|$)~', $root);
    }

    public function can_read_file(string $path, string $root): bool {
        $real = realpath($path);

        if (!is_string($real) || !str_starts_with($real, trailingslashit($root))) {
            return false;
        }

        if (str_contains($real, '/.') || preg_match('~/(plugins|themes|mu-plugins)(/|$)~', $real)) {
            return false;
        }

        return $this->extension_is_readable($real) && $this->size_is_readable($real);
    }

    private function extension_is_readable(string $path): bool {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, self::BLOCKED_EXTENSIONS, strict: true)) {
            return false;
        }

        return in_array($extension, self::TEXT_EXTENSIONS, strict: true);
    }

    private function size_is_readable(string $path): bool {
        $size = filesize($path);

        return is_int($size) && $size > 0 && $size <= $this->max_file_size() && is_readable($path);
    }
}
