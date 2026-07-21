<?php

/**
 * List/search/knowledge tool transcript formatting.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Formats list, search, and knowledge tool outputs.
 */
final class ToolResultListFormatter {
    /**
     * @param array<array-key, mixed> $output
     */
    public function format(string $tool, array $output): string {
        return match ($tool) {
            'awpt/search-content' => $this->format_content_search($output),
            'awpt/list-content' => $this->format_content_list($output),
            default => '',
        };
    }

    private function format_content_search(array $output): string {
        $results = $this->list_items($output['results'] ?? []);
        $query = (string) ($output['query'] ?? '');

        if ([] === $results) {
            return sprintf(
                /* translators: %s: search query */
                __('No content matched "%s".', 'agent-wordpress-terminal'),
                $query,
            );
        }

        $lines = [sprintf(
            /* translators: %s: search query */
            __('Content matches for "%s":', 'agent-wordpress-terminal'),
            $query,
        )];

        foreach (array_slice($results, 0, 8) as $result) {
            $lines[] = sprintf(
                /* translators: 1: post ID, 2: title, 3: post type, 4: status, 5: match reason */
                __('- #%1$d %2$s (%3$s, %4$s; %5$s)', 'agent-wordpress-terminal'),
                (int) ($result['id'] ?? 0),
                (string) ($result['title'] ?? ''),
                (string) ($result['type'] ?? ''),
                (string) ($result['status'] ?? ''),
                (string) ($result['matched_by'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $output
     */

    private function format_content_list(array $output): string {
        $items = $this->list_items($output['items'] ?? []);
        $filters = is_array($output['filters'] ?? null) ? $output['filters'] : [];
        $lines = $this->content_list_header_lines($output);

        if ([] === $items) {
            $lines[] = __('No matching items were returned in this page.', 'agent-wordpress-terminal');

            return implode("\n", $lines);
        }

        $lines[] = sprintf(
            /* translators: 1: number of listed items, 2: sort field, 3: sort direction */
            __('Showing %1$d items sorted by %2$s %3$s:', 'agent-wordpress-terminal'),
            count($items),
            (string) ($filters['orderby'] ?? 'modified'),
            (string) ($filters['order'] ?? 'DESC'),
        );

        foreach (array_slice(\AWPT\Support\ArrayKey::list_of_maps($items), 0, 10) as $item) {
            $lines[] = $this->format_content_list_item($item);
        }

        if (true === ($output['has_more'] ?? false)) {
            $lines[] = sprintf(
                /* translators: %d: remaining item count */
                __('…and %d more. Increase offset to paginate.', 'agent-wordpress-terminal'),
                max(0, (int) ($output['total'] ?? 0) - (int) ($filters['offset'] ?? 0) - count($items)),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $output
     * @return list<string>
     */

    private function content_list_header_lines(array $output): array {
        $filters = is_array($output['filters'] ?? null) ? $output['filters'] : [];
        $post_type = (string) ($output['post_type'] ?? 'post');
        $type_label = 'all' === $post_type
            ? __('matching content items', 'agent-wordpress-terminal')
            : sprintf(
                /* translators: %s: post type slug */
                __('matching %s items', 'agent-wordpress-terminal'),
                $post_type,
            );
        $lines = [
            sprintf(
                /* translators: 1: total count, 2: post type label, 3: status breakdown */
                __('Found %1$d %2$s%3$s.', 'agent-wordpress-terminal'),
                (int) ($output['total'] ?? 0),
                $type_label,
                $this->status_breakdown_suffix($this->normalize_int_map($output['totals_by_status'] ?? null)),
            ),
        ];

        $filter_line = $this->format_active_filters($filters);

        if ('' !== $filter_line) {
            $lines[] = $filter_line;
        }

        $type_totals = $this->normalize_int_map($output['totals_by_type'] ?? null);

        if ([] !== $type_totals) {
            $parts = [];

            foreach ($type_totals as $type => $count) {
                $parts[] = sprintf('%d %s', $count, $type);
            }

            $lines[] = sprintf(
                /* translators: %s: comma-separated post type totals */
                __('Totals by type: %s.', 'agent-wordpress-terminal'),
                implode(', ', $parts),
            );
        }

        return $lines;
    }

    /**
     * @param array<array-key, mixed> $item
     */

    private function format_content_list_item(array $item): string {
        $author = trim((string) ($item['author'] ?? ''));

        if ('' !== $author) {
            return sprintf(
                /* translators: 1: post ID, 2: title, 3: post type, 4: status, 5: author display name */
                __('- #%1$d %2$s (%3$s, %4$s; by %5$s)', 'agent-wordpress-terminal'),
                (int) ($item['id'] ?? 0),
                (string) ($item['title'] ?? ''),
                (string) ($item['type'] ?? ''),
                (string) ($item['status'] ?? ''),
                $author,
            );
        }

        return sprintf(
            /* translators: 1: post ID, 2: title, 3: post type, 4: status */
            __('- #%1$d %2$s (%3$s, %4$s)', 'agent-wordpress-terminal'),
            (int) ($item['id'] ?? 0),
            (string) ($item['title'] ?? ''),
            (string) ($item['type'] ?? ''),
            (string) ($item['status'] ?? ''),
        );
    }

    /**
     * @return array<string, int>
     */

    private function normalize_int_map(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $totals = [];

        foreach ($value as $key => $count) {
            if (!is_string($key)) {
                continue;
            }

            $totals[$key] = (int) $count;
        }

        return $totals;
    }

    /**
     * @param array<string, int> $totals_by_status
     */

    private function status_breakdown_suffix(array $totals_by_status): string {
        if ([] === $totals_by_status) {
            return '';
        }

        $parts = [];

        foreach ($totals_by_status as $status => $count) {
            $parts[] = sprintf('%d %s', $count, $status);
        }

        return ' (' . implode(', ', $parts) . ')';
    }

    /**
     * @param array<array-key, mixed> $filters
     */

    private function format_active_filters(array $filters): string {
        $parts = [];

        if ('' !== (string) ($filters['search'] ?? '')) {
            $parts[] = sprintf(
                /* translators: %s: search term */
                __('search "%s"', 'agent-wordpress-terminal'),
                (string) $filters['search'],
            );
        }

        if ('' !== (string) ($filters['author'] ?? '')) {
            $parts[] = sprintf(
                /* translators: %s: author identifier */
                __('author %s', 'agent-wordpress-terminal'),
                (string) $filters['author'],
            );
        }

        if ('' !== (string) ($filters['status'] ?? '')) {
            $parts[] = sprintf(
                /* translators: %s: post status */
                __('status %s', 'agent-wordpress-terminal'),
                (string) $filters['status'],
            );
        }

        if ((int) ($filters['offset'] ?? 0) > 0) {
            $parts[] = sprintf(
                /* translators: %d: offset */
                __('offset %d', 'agent-wordpress-terminal'),
                (int) $filters['offset'],
            );
        }

        if ([] === $parts) {
            return '';
        }

        return sprintf(
            /* translators: %s: comma-separated active filters */
            __('Active filters: %s.', 'agent-wordpress-terminal'),
            implode(', ', $parts),
        );
    }

    private function list_items(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }
}
