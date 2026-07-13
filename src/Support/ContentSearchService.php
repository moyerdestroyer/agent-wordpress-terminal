<?php

/**
 * WordPress content search service.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Combines exact ID/URL/slug resolution with bounded WP_Query search.
 */
final class ContentSearchService {
    private ContentSearchTypes $types;

    public function __construct(?ContentSearchTypes $types = null) {
        $this->types = $types ?? new ContentSearchTypes();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function search(array $input): array {
        $query = trim((string) ($input['query'] ?? ''));
        $limit = max(1, min(25, (int) ($input['limit'] ?? 10)));
        $post_types = $this->types->from_requested((string) ($input['post_type'] ?? ''));
        $results = [];

        foreach ($this->exact_candidates($query, $post_types) as $post) {
            $this->add_result($results, $post, 'exact', $limit);
        }

        $this->add_text_search_results($results, $query, $post_types, $limit);

        return [
            'query' => $query,
            'results' => array_values($results),
            'count' => count($results),
        ];
    }

    /**
     * @param list<string> $post_types
     * @return list<\WP_Post>
     */
    private function exact_candidates(string $query, array $post_types): array {
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

    /**
     * @param array<int, array<string, mixed>> $results
     * @param list<string>                     $post_types
     */
    private function add_text_search_results(array &$results, string $query, array $post_types, int $limit): void {
        if (count($results) >= $limit || '' === $query || !class_exists('WP_Query')) {
            return;
        }

        $search_args = [
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            's' => $query,
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ($this->supports_title_only_search()) {
            $search_args['search_columns'] = ['post_title', 'post_name'];
        }

        $search = new \WP_Query($search_args);

        foreach ($search->posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $this->add_result($results, $post, 'search', $limit);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function add_result(array &$results, \WP_Post $post, string $matched_by, int $limit): void {
        if (count($results) >= $limit || !current_user_can('read_post', $post->ID)) {
            return;
        }

        if (array_key_exists($post->ID, $results)) {
            return;
        }

        $results[$post->ID] = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'url' => get_permalink($post),
            'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
            'matched_by' => $matched_by,
        ];
    }

    private function supports_title_only_search(): bool {
        return class_exists('WP_Query') && version_compare(get_bloginfo('version'), '6.2', '>=');
    }
}
