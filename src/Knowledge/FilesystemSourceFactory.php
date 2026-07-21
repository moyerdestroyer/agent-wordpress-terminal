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
        if (!$this->policy->can_read_file($path, $root, $root_type)) {
            return null;
        }

        $real = (string) realpath($path);
        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $content = 'pdf' === $extension ? $this->pdf->extract($real) : $this->read_text_file($real, $extension);

        if ('' === trim($content)) {
            return null;
        }

        $size = filesize($real);

        return [
            'kind' => 'filesystem',
            'source_id' => 'file:' . hash('sha256', $real),
            'post_id' => null,
            'path' => $real,
            'label' => basename($real),
            'uri' => $real,
            'content' => $content,
            'modified_at' => gmdate('Y-m-d H:i:s', (int) filemtime($real)),
            'metadata' => [
                'extension' => $extension,
                'size' => is_int($size) ? $size : 0,
                'root' => $root,
                'root_type' => $root_type,
            ],
        ];
    }

    private function read_text_file(string $path, string $extension): string {
        $content = file_get_contents($path);

        if (!is_string($content)) {
            return '';
        }

        if (in_array($extension, ['html', 'htm'], true)) {
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
