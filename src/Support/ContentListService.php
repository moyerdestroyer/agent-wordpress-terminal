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
final class ContentListService
{
    private ContentSearchTypes $types;
    private ContentListFilters $filters;
    private ContentListQueryArgs $query_args;
    private ContentListTotals $totals;
    private ContentListItemFormatter $items;
    private ContentListPostLoader $loader;

    public function __construct()
    {
        $this->types = new ContentSearchTypes();
        $this->filters = new ContentListFilters();
        $this->query_args = new ContentListQueryArgs();
        $this->totals = new ContentListTotals($this->types);
        $this->items = new ContentListItemFormatter();
        $this->loader = new ContentListPostLoader();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function list(array $input): array
    {
        $filters = $this->filters->from_input($input);
        $post_type = (string) $filters['post_type'];
        $status = (string) $filters['status'];
        $include_total = (bool) $filters['include_total'];
        $include_totals = (bool) $filters['include_totals'];
        $offset = (int) $filters['offset'];
        $post_types = $this->resolve_post_types($post_type);
        $statuses = $this->filters->resolve_statuses($status);
        $items = [];

        if (!class_exists('WP_Query')) {
            return $this->empty_result($filters, $post_types);
        }

        $query = new \WP_Query($this->query_args->build($filters, $post_types, $statuses));
        /** @var list<\WP_Post|int|string> $query_posts */
        $query_posts = array_values($query->posts);
        $post_ids = $this->query_args->post_ids_from_query($query_posts);
        $posts = $this->loader->load($post_ids);

        foreach ($posts as $post) {
            if (!current_user_can('read_post', $post->ID) || array_key_exists($post->ID, $items)) {
                continue;
            }

            $items[$post->ID] = $this->items->from_post($post);
        }

        $count = count($items);
        $total = $include_total ? (int) $query->found_posts : $count;
        $inventory = $include_totals ? $this->totals->inventory($post_types) : ['by_status' => [], 'by_type' => []];

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
     * @return list<string>
     */
    private function resolve_post_types(string $requested): array
    {
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
    private function should_include_type_totals(string $requested_type, array $post_types): bool
    {
        return 'all' === $requested_type || count($post_types) > 1;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $post_types
     * @return array<string, mixed>
     */
    private function empty_result(array $filters, array $post_types): array
    {
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
