<?php

/**
 * WordPress content listing service.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists readable WordPress content with filters, sorting, and inventory totals.
 */
final class ContentListService {
    /**
     * @var list<string>
     */
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future', 'inherit'];

    private ContentSearchTypes $types;

    private ContentListFilters $filters;

    public function __construct(?ContentSearchTypes $types = null) {
        $this->types = $types ?? new ContentSearchTypes();
        $this->filters = new ContentListFilters();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function list(array $input): array {
        $filters = $this->filters->from_input($input);
        $post_type = (string) $filters['post_type'];
        $status = (string) $filters['status'];
        $include_total = (bool) $filters['include_total'];
        $include_totals = (bool) $filters['include_totals'];
        $offset = (int) $filters['offset'];
        $post_types = $this->resolve_post_types($post_type);
        $attachment_only = ['attachment'] === $post_types;

        if ($attachment_only && in_array($status, ['', 'publish'], true)) {
            // Media Library attachments normally use `inherit`; models commonly
            // send the ordinary-content default `publish`. Treat both as a media
            // browse rather than falsely reporting an empty library.
            $status = 'inherit';
            $filters['status'] = 'inherit';
            $statuses = ['inherit'];
        } else {
            $statuses = $this->filters->resolve_statuses($status);
        }

        if (!$attachment_only && in_array('attachment', $post_types, true) && '' === $status) {
            $statuses[] = 'inherit';
        }
        $items = [];

        if (!class_exists('WP_Query')) {
            /** @var array<string, mixed> $filters */
            return $this->empty_result($filters, $post_types);
        }

        $query = new \WP_Query($this->query_args($filters, $post_types, $statuses));
        /** @var list<\WP_Post|int|string> $query_posts */
        $query_posts = array_values($query->posts);
        $post_ids = $this->post_ids_from_query($query_posts);

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);

            if (!$post instanceof \WP_Post) {
                continue;
            }

            if (
                new NewPostStagingDraft()->is_staging_draft($post->ID)
                || !current_user_can('read_post', $post->ID)
                || array_key_exists($post->ID, $items)
            ) {
                continue;
            }

            $items[$post->ID] = $this->item_from_post($post);
        }

        $count = count($items);
        $total = $include_total ? (int) $query->found_posts : $count;
        $inventory = $include_totals ? $this->inventory($post_types) : ['by_status' => [], 'by_type' => []];

        return [
            'post_type' => $post_type,
            'post_types' => $post_types,
            'status' => $status,
            'filters' => $filters,
            'items' => array_values($items),
            'count' => $count,
            'total' => $total,
            'has_more' => $include_total && ($offset + $count) < $total,
            'totals_by_status' => $inventory['by_status'],
            'totals_by_type' => $this->should_include_type_totals($post_type, $post_types) ? $inventory['by_type'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */

    private function query_args(array $filters, array $post_types, array $statuses): array {
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
            'meta_query' => [[
                'key' => NewPostStagingDraft::META_KEY,
                'compare' => 'NOT EXISTS',
            ]],
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
    private function post_ids_from_query(array $posts): array {
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

    /**
     * @return array<string, mixed>
     */
    private function item_from_post(\WP_Post $post): array {
        $author_id = (int) $post->post_author;

        return [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'author_id' => $author_id,
            'author' => $this->author_display_name($author_id),
            'created' => $post->post_date_gmt,
            'modified' => $post->post_modified_gmt,
            'excerpt' => trim($post->post_excerpt),
            'url' => get_permalink($post),
            'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
        ];
    }

    private function author_display_name(int $author_id): string {
        if ($author_id <= 0 || !function_exists('get_userdata')) {
            return '';
        }

        $user = get_userdata($author_id);

        return $user instanceof \WP_User ? $user->display_name : '';
    }

    /**
     * @param list<string> $post_types
     * @return array{by_status: array<string, int>, by_type: array<string, int>}
     */
    private function inventory(array $post_types): array {
        if (!function_exists('wp_count_posts')) {
            return [
                'by_status' => [],
                'by_type' => [],
            ];
        }

        $types = [] !== $post_types ? $post_types : $this->types->from_requested('');
        $by_status = [];
        $by_type = [];

        foreach ($types as $post_type) {
            $counts = wp_count_posts($post_type);
            $type_total = 0;

            foreach (self::STATUSES as $status) {
                $count = (int) ($counts->{$status} ?? 0);
                $type_total += $count;

                if ($count > 0) {
                    $by_status[$status] = ($by_status[$status] ?? 0) + $count;
                }
            }

            if ($type_total > 0) {
                $by_type[$post_type] = $type_total;
            }
        }

        return [
            'by_status' => $by_status,
            'by_type' => $by_type,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolve_post_types(string $requested): array {
        if ('all' === $requested) {
            return $this->types->from_requested('');
        }

        if ('' === $requested) {
            return ['post'];
        }

        $resolved = $this->types->from_requested($requested);

        if (1 === count($resolved) && $resolved[0] === $requested) {
            return $resolved;
        }

        return ['post'];
    }

    /**
     * @param list<string> $post_types
     */
    private function should_include_type_totals(string $requested_type, array $post_types): bool {
        return 'all' === $requested_type || count($post_types) > 1;
    }

    private function supports_title_only_search(): bool {
        return class_exists('WP_Query') && version_compare(get_bloginfo('version'), '6.2', '>=');
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $post_types
     * @return array<string, mixed>
     */
    private function empty_result(array $filters, array $post_types): array {
        return [
            'post_type' => (string) $filters['post_type'],
            'post_types' => $post_types,
            'status' => (string) $filters['status'],
            'filters' => $filters,
            'items' => [],
            'count' => 0,
            'total' => 0,
            'has_more' => false,
            'totals_by_status' => [],
            'totals_by_type' => [],
        ];
    }
}
