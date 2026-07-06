<?php

/**
 * Builds WP_Query arguments for content listing.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Constructs optimized WP_Query args for list-content.
 */
final class ContentListQueryArgs
{
    /**
     * @param array<string, mixed> $filters
     * @param list<string> $post_types
     * @param list<string> $statuses
     * @return array<string, mixed>
     */
    public function build(array $filters, array $post_types, array $statuses): array
    {
        $args = [
            'post_type' => $post_types,
            'post_status' => $statuses,
            'posts_per_page' => (int) $filters['limit'],
            'offset' => (int) $filters['offset'],
            'orderby' => (string) $filters['orderby'],
            'order' => (string) $filters['order'],
            'fields' => 'ids',
            'no_found_rows' => !$filters['include_total'],
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        $author_id = (int) ($filters['author_id'] ?? 0);
        $search = (string) ($filters['search'] ?? '');

        if ($author_id > 0) {
            $args['author'] = $author_id;
        }

        if ('' !== $search) {
            $args['s'] = $search;

            if ($this->supports_title_only_search()) {
                $args['search_columns'] = ['post_title', 'post_name'];
            }
        }

        return $args;
    }

    /**
     * @param list<\WP_Post|int|string> $posts
     * @return list<int>
     */
    public function post_ids_from_query(array $posts): array
    {
        $ids = [];

        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $ids[] = $post->ID;
                continue;
            }

            $ids[] = (int) $post;
        }

        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    private function supports_title_only_search(): bool
    {
        return class_exists('WP_Query') && version_compare(get_bloginfo('version'), '6.2', '>=');
    }
}
