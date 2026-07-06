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
    private const CANDIDATES = ['post', 'page', 'attachment', 'wp_block', 'wp_template', 'wp_template_part'];

    /**
     * @return list<string>
     */
    public function installed(): array {
        return array_values(array_filter(self::CANDIDATES, static fn(string $post_type): bool => post_type_exists(
            $post_type,
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

            foreach (['publish', 'draft', 'pending', 'private'] as $status) {
                $eligible += (int) ($counts->{$status} ?? 0);
            }
        }

        return ['cap' => $cap, 'eligible' => $eligible];
    }
}
