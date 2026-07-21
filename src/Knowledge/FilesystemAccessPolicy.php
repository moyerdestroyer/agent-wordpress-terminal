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
 * Policy is intentionally read-only and document-oriented under wp-content.
 */
final class FilesystemAccessPolicy {
    public const DEFAULT_MAX_FILE_SIZE = 2_097_152;

    public const ROOT_THEME = 'theme';

    public const ROOT_UPLOADS = 'uploads';

    public const ROOT_CUSTOM = 'custom';

    /**
     * Extensions preferred for text-like indexing.
     *
     * @var list<string>
     */
    private const DOCUMENT_EXTENSIONS = [
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
        'pdf',
    ];

    /**
     * Theme files useful as design and structure context.
     *
     * @var list<string>
     */
    private const THEME_EXTENSIONS = ['txt', 'md', 'markdown', 'html', 'htm', 'json', 'css', 'scss'];

    /**
     * Dependency and generated trees that should never become Knowledge.
     *
     * @var list<string>
     */
    private const EXCLUDED_DIRECTORIES = [
        'node_modules',
        'vendor',
        'build',
        'dist',
        'coverage',
        'cache',
        '.cache',
        '.git',
        '.github',
        '.svn',
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

        return $root === $content_dir || str_starts_with($root, trailingslashit($content_dir));
    }

    public function can_read_file(string $path, string $root, string $root_type = self::ROOT_CUSTOM): bool {
        $real = realpath($path);
        $root_real = realpath($root);

        if (!is_string($real) || !is_string($root_real)) {
            return false;
        }

        if (!str_starts_with($real, trailingslashit($root_real)) && $real !== $root_real) {
            return false;
        }

        if ($this->path_has_excluded_segment($real, $root_real)) {
            return false;
        }

        return $this->file_is_relevant($real, $root_real, $root_type) && $this->size_is_readable($real);
    }

    public function can_traverse_directory(string $path, string $root): bool {
        $real = realpath($path);
        $root_real = realpath($root);

        if (!is_string($real) || !is_string($root_real)) {
            return false;
        }

        return !$this->path_has_excluded_segment($real, $root_real);
    }

    private function file_is_relevant(string $path, string $root, string $root_type): bool {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (self::ROOT_THEME === $root_type) {
            return $this->theme_file_is_relevant($path, $root, $extension);
        }

        return in_array($extension, self::DOCUMENT_EXTENSIONS, true);
    }

    private function theme_file_is_relevant(string $path, string $root, string $extension): bool {
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        $basename = strtolower(basename($relative));

        if (1 === preg_match('~^(readme|contributing|changelog|license)(\.[^.]+)?$~i', $basename)) {
            return true;
        }

        if (!in_array($extension, self::THEME_EXTENSIONS, true)) {
            return false;
        }

        if (in_array($basename, ['theme.json', 'style.css', 'style.min.css'], true)) {
            return true;
        }

        if (in_array($extension, ['css', 'scss'], true)) {
            return true;
        }

        // Theme author docs (CivicPress docs/, AGENTS guides, pattern write-ups).
        if (
            in_array($extension, ['txt', 'md', 'markdown'], true)
            && preg_match('~^(docs|documentation|doc)/~', $relative)
        ) {
            return true;
        }

        if (preg_match('~^(styles|templates|parts|patterns)/~', $relative)) {
            return true;
        }

        return false;
    }

    private function path_has_excluded_segment(string $path, string $root): bool {
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        $segments = explode('/', strtolower($relative));

        foreach ($segments as $segment) {
            if ('' === $segment) {
                continue;
            }

            if (str_starts_with($segment, '.') || in_array($segment, self::EXCLUDED_DIRECTORIES, true)) {
                return true;
            }
        }

        return false;
    }

    private function size_is_readable(string $path): bool {
        $size = filesize($path);

        return is_int($size) && $size > 0 && $size <= $this->max_file_size() && is_readable($path);
    }
}
