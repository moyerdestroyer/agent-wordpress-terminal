<?php

/**
 * Filesystem source factory for Knowledge ingestion.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

defined('ABSPATH') || exit();

/**
 * Converts an allowed file into an indexable source.
 */
final class FilesystemSourceFactory
{
    private FilesystemAccessPolicy $policy;

    public function __construct(?FilesystemAccessPolicy $policy = null)
    {
        $this->policy = $policy ?? new FilesystemAccessPolicy();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function from_file(string $path, string $root): ?array
    {
        if (!$this->policy->can_read_file($path, $root)) {
            return null;
        }

        $real = (string) realpath($path);
        $content = $this->read_text_file($real);

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
                'extension' => strtolower(pathinfo($real, PATHINFO_EXTENSION)),
                'size' => is_int($size) ? $size : 0,
                'root' => $root,
            ],
        ];
    }

    private function read_text_file(string $path): string
    {
        $content = file_get_contents($path);

        if (!is_string($content)) {
            return '';
        }

        return wp_strip_all_tags(mb_substr($content, 0, $this->policy->max_file_size(), 'UTF-8'));
    }
}
