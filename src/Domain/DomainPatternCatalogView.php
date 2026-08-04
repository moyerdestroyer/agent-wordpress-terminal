<?php

/**
 * Human-facing summaries for active Domain Pack patterns.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;
use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

final class DomainPatternCatalogView {
    /** @return list<array<string, mixed>> */
    public function items(): array {
        $items = [];

        foreach (new PatternCatalog()->list('', 200) as $pattern) {
            $domain = ArrayKey::as_map($pattern['domain'] ?? null);
            $pack_id = sanitize_key((string) ($domain['pack_id'] ?? ''));

            if ('' === $pack_id) {
                continue;
            }

            $preview = $this->preview($pack_id, (string) ($pattern['name'] ?? ''), $domain);
            $items[] = [
                'name' => (string) ($pattern['name'] ?? ''),
                'title' => (string) ($pattern['title'] ?? ''),
                'description' => (string) ($pattern['description'] ?? ''),
                'owner' => (string) ($pattern['owner'] ?? ''),
                'compatibility' => (string) ($pattern['compatibility'] ?? ''),
                'composition_scope' => (string) ($pattern['composition_scope'] ?? ''),
                'block_count' => (int) ($pattern['block_count'] ?? 0),
                'pack_id' => $pack_id,
                'role' => (string) ($domain['role'] ?? ''),
                'summary' => (string) ($domain['summary'] ?? ''),
                'intents' => ArrayKey::list_of_strings($domain['intents'] ?? null),
                'dynamic_content' => (bool) ($domain['dynamic_content'] ?? false),
                'post_types' => ArrayKey::list_of_strings($domain['post_types'] ?? null),
                'slot_count' => count(ArrayKey::list_of_maps($domain['slots'] ?? null)),
                'docs' => (string) ($domain['docs'] ?? ''),
                ...$preview,
            ];
        }

        usort($items, static fn(array $left, array $right): int => strnatcasecmp(
            (string) ($left['title'] ?? ''),
            (string) ($right['title'] ?? ''),
        ));

        return $items;
    }

    /**
     * @param array<string, mixed> $domain
     * @return array{preview_url: string, preview_alt: string, preview_source: string}
     */
    private function preview(string $pack_id, string $pattern_name, array $domain): array {
        $pack = DomainPackRegistry::instance()->get($pack_id);

        if (!is_array($pack)) {
            return ['preview_url' => '', 'preview_alt' => '', 'preview_source' => ''];
        }

        $preview = ArrayKey::as_map($domain['preview'] ?? null);
        $relative = (string) ($preview['image'] ?? '');
        $source = 'declared';

        if ('' === $relative) {
            $relative =
                'tests/e2e/specs/__snapshots__/'
                . str_replace('/', '--', sanitize_text_field($pattern_name))
                . '-chromium-linux.png';
            $source = 'development_snapshot';
        }

        $root = (string) ($pack['_root'] ?? '');
        $path = '' !== $root ? realpath(trailingslashit($root) . ltrim($relative, '/')) : false;

        if (
            !is_string($path)
            || !str_starts_with($path, trailingslashit($root))
            || !is_file($path)
            || !function_exists('get_theme_root')
            || !function_exists('get_theme_root_uri')
        ) {
            return ['preview_url' => '', 'preview_alt' => '', 'preview_source' => ''];
        }

        $theme_root = realpath(get_theme_root());

        if (!is_string($theme_root) || !str_starts_with($path, trailingslashit($theme_root))) {
            return ['preview_url' => '', 'preview_alt' => '', 'preview_source' => ''];
        }

        $relative_to_themes = ltrim(str_replace('\\', '/', substr($path, strlen($theme_root))), '/');
        $segments = array_map('rawurlencode', explode('/', $relative_to_themes));

        return [
            'preview_url' => esc_url_raw(trailingslashit(get_theme_root_uri()) . implode('/', $segments)),
            'preview_alt' => sanitize_text_field((string) ($preview['alt'] ?? '')),
            'preview_source' => $source,
        ];
    }
}
