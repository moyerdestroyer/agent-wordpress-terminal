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
    private ContentSearchExactResolver $exact;
    private ContentSearchResultSet $results;

    public function __construct(
        ?ContentSearchTypes $types = null,
        ?ContentSearchExactResolver $exact = null,
        ?ContentSearchResultSet $results = null,
    ) {
        $this->types = $types ?? new ContentSearchTypes();
        $this->exact = $exact ?? new ContentSearchExactResolver();
        $this->results = $results ?? new ContentSearchResultSet();
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

        foreach ($this->exact->candidates($query, $post_types) as $post) {
            $this->results->add($results, $post, 'exact', $limit);
        }

        $this->add_text_search_results($results, $query, $post_types, $limit);

        return [
            'query' => $query,
            'results' => array_values($results),
            'count' => count($results),
        ];
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

            $this->results->add($results, $post, 'search', $limit);
        }
    }

    private function supports_title_only_search(): bool {
        return class_exists('WP_Query') && version_compare(get_bloginfo('version'), '6.2', '>=');
    }
}
