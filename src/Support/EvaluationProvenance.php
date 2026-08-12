<?php

/**
 * Reproducibility metadata for local evaluation artifacts.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Domain\DomainPackRegistry;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Captures the code, theme, domain-pack, and pattern-catalog identity of a run.
 */
final class EvaluationProvenance {
    /** @return array<string, mixed> */
    public static function collect(string $post_type): array {
        $plugin_root = dirname(__DIR__, 2);
        $commit = self::git($plugin_root, 'rev-parse --verify HEAD');
        $status = self::git($plugin_root, 'status --porcelain --untracked-files=normal');
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;

        $packs = array_values(array_map(static fn(array $pack): array => [
            'id' => (string) ($pack['id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'schema_version' => (int) ($pack['schema_version'] ?? 0),
            'source' => (string) ($pack['_source'] ?? ''),
        ], DomainPackRegistry::instance()->active()));

        return [
            'plugin_commit' => $commit,
            'plugin_dirty' => null === $status ? null : '' !== $status,
            'active_theme' => function_exists('get_stylesheet') ? get_stylesheet() : '',
            'active_theme_version' => is_object($theme) && method_exists($theme, 'get') ? $theme->get('Version') : '',
            'active_domain_packs' => $packs,
            'pattern_catalog_hash' => hash(
                'sha256',
                (string) wp_json_encode(new PatternCatalog()->list('', 200, $post_type)),
            ),
        ];
    }

    private static function git(string $root, string $arguments): ?string {
        if (!function_exists('exec')) {
            return null;
        }

        $output = [];
        $exit_code = 1;
        exec('git -C ' . escapeshellarg($root) . ' ' . $arguments . ' 2>/dev/null', $output, $exit_code);

        return 0 === $exit_code ? trim(implode("\n", $output)) : null;
    }
}
