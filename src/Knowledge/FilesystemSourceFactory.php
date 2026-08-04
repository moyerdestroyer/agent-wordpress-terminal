<?php

/**
 * Filesystem source factory for Knowledge ingestion.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts a readable file into an indexable source.
 */
final class FilesystemSourceFactory {
    private FilesystemAccessPolicy $policy;
    private PdfTextExtractor $pdf;

    public function __construct(?FilesystemAccessPolicy $policy = null, ?PdfTextExtractor $pdf = null) {
        $this->policy = $policy ?? new FilesystemAccessPolicy();
        $this->pdf = $pdf ?? new PdfTextExtractor();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function from_file(
        string $path,
        string $root,
        string $root_type = FilesystemAccessPolicy::ROOT_CUSTOM,
    ): ?array {
        $descriptor = $this->describe_file($path, $root, $root_type);

        return null !== $descriptor ? $this->load_descriptor($descriptor) : null;
    }

    /**
     * Build a cheap source descriptor without reading or extracting file content.
     *
     * @return array<string, mixed>|null
     */
    public function describe_file(
        string $path,
        string $root,
        string $root_type = FilesystemAccessPolicy::ROOT_CUSTOM,
    ): ?array {
        if (!$this->policy->can_read_file($path, $root, $root_type)) {
            return null;
        }

        $real = (string) realpath($path);

        if (FilesystemAccessPolicy::ROOT_CUSTOM === $root_type && $this->is_media_library_file($real)) {
            return null;
        }

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $size = filesize($real);
        $modified = filemtime($real);
        $relative = $this->relative_path($real, $root);
        $label = '' !== $relative ? $relative : basename($real);
        $normalized_relative = strtolower(str_replace('\\', '/', $relative));
        $audience = 'agents.md' === strtolower(basename($relative))
        || str_contains($normalized_relative, '/agents/')
        || str_starts_with($normalized_relative, 'docs/agents/')
            ? 'developer'
            : 'site';

        if (FilesystemAccessPolicy::ROOT_THEME === $root_type) {
            $label = 'theme:' . $label;
        } elseif (FilesystemAccessPolicy::ROOT_UPLOADS === $root_type) {
            $label = 'uploads:' . $label;
        }

        return [
            'kind' => 'filesystem',
            'source_id' => 'file:' . hash('sha256', $real),
            'post_id' => null,
            'path' => $real,
            'label' => $label,
            'uri' => $real,
            'content' => '',
            'content_type' => $this->content_type($extension),
            'semantic_eligible' => !in_array($extension, ['css', 'scss'], true),
            'discovery_fingerprint' => hash('sha256', implode(':', [
                KnowledgeIndexProfile::SOURCE_POLICY_VERSION,
                $real,
                (string) (is_int($size) ? $size : 0),
                (string) (is_int($modified) ? $modified : 0),
            ])),
            'modified_at' => gmdate('Y-m-d H:i:s', (int) $modified),
            'metadata' => [
                'extension' => $extension,
                'size' => is_int($size) ? $size : 0,
                'root' => $root,
                'root_type' => $root_type,
                'relative_path' => $relative,
                'audience' => $audience,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>|null
     */
    public function load_descriptor(array $descriptor): ?array {
        $path = (string) ($descriptor['path'] ?? '');
        $extension = strtolower((string) ($descriptor['metadata']['extension'] ?? ''));

        if ('' === $path || !is_readable($path)) {
            return null;
        }

        $content = 'pdf' === $extension ? $this->pdf->extract($path) : $this->read_text_file($path, $extension);

        if ('' === trim($content)) {
            return null;
        }

        $descriptor['content'] = $content;

        return $descriptor;
    }

    private function content_type(string $extension): string {
        return match ($extension) {
            'json' => 'json',
            'csv' => 'csv',
            'pdf' => 'pdf',
            'css' => 'css',
            'scss' => 'scss',
            'html', 'htm', 'php' => 'gutenberg',
            default => 'prose',
        };
    }

    private function is_media_library_file(string $path): bool {
        if (!function_exists('attachment_url_to_postid')) {
            return false;
        }

        $uploads = wp_get_upload_dir();
        $basedir = is_string($uploads['basedir'] ?? null) ? rtrim($uploads['basedir'], '/\\') : '';
        $baseurl = is_string($uploads['baseurl'] ?? null) ? rtrim($uploads['baseurl'], '/') : '';

        if ('' === $basedir || '' === $baseurl || !str_starts_with($path, trailingslashit($basedir))) {
            return false;
        }

        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($basedir))), '/');

        return attachment_url_to_postid($baseurl . '/' . $relative) > 0;
    }

    private function relative_path(string $path, string $root): string {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if ($path === $root) {
            return basename($path);
        }

        $prefix = $root . '/';

        if (str_starts_with($path, $prefix)) {
            return ltrim(substr($path, strlen($prefix)), '/');
        }

        return basename($path);
    }

    private function read_text_file(string $path, string $extension): string {
        $content = file_get_contents($path);

        if (!is_string($content)) {
            return '';
        }

        if (in_array($extension, ['html', 'htm', 'php'], true)) {
            $content = $this->preserve_wordpress_block_markup($content);
        }

        return wp_strip_all_tags(mb_substr($content, 0, $this->policy->max_file_size(), 'UTF-8'));
    }

    private function preserve_wordpress_block_markup(string $content): string {
        $normalized = preg_replace_callback(
            '~<!--\s*(/?)wp:([^\s]+)\s*(.*?)-->~is',
            static function (array $matches): string {
                $direction = '' !== ($matches[1] ?? '') ? 'End' : 'Start';
                $name = trim($matches[2] ?? 'block');
                $attributes = trim(rtrim($matches[3] ?? '', '/'));

                return sprintf(
                    "\n%s WordPress block %s%s\n",
                    $direction,
                    $name,
                    '' !== $attributes ? ' ' . $attributes : '',
                );
            },
            $content,
        );

        return is_string($normalized) ? $normalized : $content;
    }
}
