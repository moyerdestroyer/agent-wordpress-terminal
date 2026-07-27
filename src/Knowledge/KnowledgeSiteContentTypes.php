<?php

/**
 * Site content post types eligible for knowledge indexing.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves installed site content post types for indexing.
 */
final class KnowledgeSiteContentTypes {
    /**
     * @var list<string>
     */
    private const STRUCTURAL_TYPES = ['wp_block', 'wp_template', 'wp_template_part'];

    private const FALLBACK_TYPES = ['post', 'page', 'attachment', 'wp_block', 'wp_template', 'wp_template_part'];

    /**
     * @return list<string>
     */
    public function installed(): array {
        $types = self::FALLBACK_TYPES;

        if (function_exists('get_post_types')) {
            $public = get_post_types(['public' => true, 'show_ui' => true], 'names');
            $types = [...array_values(array_map('strval', $public)), 'attachment', ...self::STRUCTURAL_TYPES];
        }

        $types = array_values(array_unique(array_filter(
            $types,
            static fn(string $post_type): bool => (
                !in_array($post_type, ['revision', 'nav_menu_item', 'custom_css', 'customize_changeset'], true)
                && post_type_exists($post_type)
            ),
        )));

        /** @var mixed $filtered */
        $filtered = apply_filters('awpt_knowledge_site_post_types', $types);

        if (!is_array($filtered)) {
            return $types;
        }

        return array_values(array_unique(array_filter(
            array_map(static fn(mixed $type): string => sanitize_key((string) $type), $filtered),
            static fn(string $type): bool => '' !== $type && post_type_exists($type),
        )));
    }

    /**
     * @return array{cap: int, eligible: int}
     */
    public function index_stats(int $cap): array {
        $post_types = $this->installed();
        $eligible = 0;

        if (!function_exists('wp_count_posts')) {
            return ['cap' => $cap, 'eligible' => 0];
        }

        foreach ($post_types as $post_type) {
            $counts = wp_count_posts($post_type);
            $statuses = 'attachment' === $post_type
                ? ['inherit', 'private']
                : ['publish', 'draft', 'pending', 'private'];

            foreach ($statuses as $status) {
                $eligible += (int) ($counts->{$status} ?? 0);
            }
        }

        return ['cap' => $cap, 'eligible' => $eligible];
    }
}
