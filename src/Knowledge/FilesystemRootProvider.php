<?php

/**
 * Filesystem root discovery for Knowledge ingestion.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves configured Knowledge document roots.
 */
final class FilesystemRootProvider {
    private FilesystemAccessPolicy $policy;

    public function __construct(?FilesystemAccessPolicy $policy = null) {
        $this->policy = $policy ?? new FilesystemAccessPolicy();
    }

    /**
     * @return list<string>
     */
    public function allowed_roots(): array {
        $normalized = [];

        foreach ($this->configured_roots() as $root) {
            $real = realpath($root);

            if (is_string($real) && is_dir($real) && $this->policy->is_allowed_root($real)) {
                $normalized[] = $real;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $roots Raw roots.
     * @return list<string>
     */
    public function sanitize_configured_roots(array $roots): array {
        $valid = [];

        foreach ($roots as $root) {
            $real = realpath(trim($root));

            if (is_string($real) && is_dir($real) && $this->policy->is_allowed_root($real)) {
                $valid[] = $real;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * @return list<string>
     */
    private function configured_roots(): array {
        $upload_dir = wp_get_upload_dir();
        $roots = [];

        if (is_string($upload_dir['basedir'] ?? null)) {
            $roots[] = $upload_dir['basedir'];
        }

        $configured = (string) get_option('awpt_knowledge_roots', '');
        $configured_roots = preg_split('/\R+/', $configured);

        foreach (is_array($configured_roots) ? $configured_roots : [] as $path) {
            $path = trim($path);

            if ('' !== $path) {
                $roots[] = $path;
            }
        }

        return $roots;
    }
}
