<?php

/**
 * Exact content search resolution.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves direct ID, URL, and slug matches before text search.
 */
final class ContentSearchExactResolver {
    /**
     * @param list<string> $post_types
     * @return list<\WP_Post>
     */
    public function candidates(string $query, array $post_types): array {
        return array_values(array_filter(
            [
                $this->post_from_id($query, $post_types),
                $this->post_from_url($query, $post_types),
                $this->post_from_slug($query, $post_types),
            ],
            static fn(mixed $post): bool => $post instanceof \WP_Post,
        ));
    }

    /**
     * @param list<string> $post_types
     */
    private function post_from_id(string $query, array $post_types): ?\WP_Post {
        if (!ctype_digit($query)) {
            return null;
        }

        $post = get_post((int) $query);

        if (!$post instanceof \WP_Post || !$this->is_allowed_post($post, $post_types)) {
            return null;
        }

        return $post;
    }

    /**
     * @param list<string> $post_types
     */
    private function post_from_url(string $query, array $post_types): ?\WP_Post {
        if ('' === $query || !str_contains($query, '://') || !function_exists('url_to_postid')) {
            return null;
        }

        $post = get_post((int) url_to_postid($query));

        if (!$post instanceof \WP_Post || !$this->is_allowed_post($post, $post_types)) {
            return null;
        }

        return $post;
    }

    /**
     * @param list<string> $post_types
     */
    private function post_from_slug(string $query, array $post_types): ?\WP_Post {
        $slug = $this->slug_from_query($query);

        if ('' === $slug || !function_exists('get_page_by_path')) {
            return null;
        }

        $post = get_page_by_path($slug, OBJECT, $post_types);

        return $post instanceof \WP_Post ? $post : null;
    }

    private function slug_from_query(string $query): string {
        $query = trim($query);

        if ('' === $query) {
            return '';
        }

        if (str_contains($query, '://')) {
            $path = (string) wp_parse_url($query, PHP_URL_PATH);
            $query = basename(trim($path, '/'));
        }

        return sanitize_title($query);
    }

    /**
     * @param list<string> $post_types
     */
    private function is_allowed_post(\WP_Post $post, array $post_types): bool {
        return in_array($post->post_type, $post_types, true);
    }
}
