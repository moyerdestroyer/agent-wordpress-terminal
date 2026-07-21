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
 * Resolves Knowledge document roots under wp-content.
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
        return array_column($this->root_definitions(), 'path');
    }

    /**
     * @return list<array{path: string, type: string}>
     */
    public function root_definitions(): array {
        $normalized = [];

        foreach ($this->configured_roots() as $definition) {
            $real = realpath($definition['path']);

            if (is_string($real) && is_dir($real) && $this->policy->is_allowed_root($real)) {
                $normalized[$real] ??= [
                    'path' => $real,
                    'type' => $definition['type'],
                ];
            }
        }

        return array_values($normalized);
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
     * @return list<array{path: string, type: string}>
     */
    private function configured_roots(): array {
        $roots = [];
        $upload_dir = wp_get_upload_dir();

        if (is_string($upload_dir['basedir'] ?? null)) {
            $roots[] = [
                'path' => $upload_dir['basedir'],
                'type' => FilesystemAccessPolicy::ROOT_UPLOADS,
            ];
        }

        $stylesheet = get_stylesheet_directory();
        $template = get_template_directory();

        if ('' !== $stylesheet) {
            $roots[] = [
                'path' => $stylesheet,
                'type' => FilesystemAccessPolicy::ROOT_THEME,
            ];
        }

        if ('' !== $template && $template !== $stylesheet) {
            $roots[] = [
                'path' => $template,
                'type' => FilesystemAccessPolicy::ROOT_THEME,
            ];
        }

        $configured = (string) get_option('awpt_knowledge_roots', '');
        $configured_roots = preg_split('/\R+/', $configured);

        foreach (is_array($configured_roots) ? $configured_roots : [] as $path) {
            $path = trim($path);

            if ('' !== $path) {
                $roots[] = [
                    'path' => $path,
                    'type' => FilesystemAccessPolicy::ROOT_CUSTOM,
                ];
            }
        }

        return $roots;
    }
}
