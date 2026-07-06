<?php

/**
 * Header lines for content list transcript formatting.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds summary/header lines for list-content tool output.
 */
final class ToolResultContentListSummary {
    /**
     * @param array<array-key, mixed> $output
     * @return list<string>
     */
    public function header_lines(array $output): array {
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
            $lines[] = $this->format_type_totals($type_totals);
        }

        return $lines;
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

    /**
     * @param array<string, int> $totals_by_type
     */
    private function format_type_totals(array $totals_by_type): string {
        $parts = [];

        foreach ($totals_by_type as $type => $count) {
            $parts[] = sprintf('%d %s', $count, $type);
        }

        return sprintf(
            /* translators: %s: comma-separated post type totals */
            __('Totals by type: %s.', 'agent-wordpress-terminal'),
            implode(', ', $parts),
        );
    }
}
