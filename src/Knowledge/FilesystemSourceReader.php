<?php

/**
 * Safe read-only filesystem source discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Discovers readable document files from approved roots.
 */
final class FilesystemSourceReader {
    private FilesystemRootProvider $roots;

    private FilesystemSourceFactory $factory;

    public function __construct(?FilesystemRootProvider $roots = null, ?FilesystemSourceFactory $factory = null) {
        $this->roots = $roots ?? new FilesystemRootProvider();
        $this->factory = $factory ?? new FilesystemSourceFactory();
    }

    /**
     * @return list<string>
     */
    public function allowed_roots(): array {
        return $this->roots->allowed_roots();
    }

    /**
     * @param list<string> $roots Raw roots.
     * @return list<string>
     */
    public function sanitize_configured_roots(array $roots): array {
        return $this->roots->sanitize_configured_roots($roots);
    }

    /**
     * Discover readable file sources.
     *
     * @return list<array<string, mixed>>
     */
    public function list_sources(): array {
        $sources = [];

        foreach ($this->roots->allowed_roots() as $root) {
            foreach ($this->files_in_root($root) as $path) {
                $source = $this->factory->from_file($path, $root);

                if (null !== $source) {
                    $sources[] = $source;
                }
            }
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function files_in_root(string $root): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        return $files;
    }
}
