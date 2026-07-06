<?php

/**
 * Formats content list tool outputs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable summaries for content listing results.
 */
final class ToolResultContentListFormatter
{
    private ToolResultArrayFormatter $arrays;

    public function __construct(?ToolResultArrayFormatter $arrays = null)
    {
        $this->arrays = $arrays ?? new ToolResultArrayFormatter();
    }

    /**
     * @param array<array-key, mixed> $output
     */
    public function format(array $output): string
    {
        $items = $this->arrays->list_items($output['items'] ?? []);
        $filters = is_array($output['filters'] ?? null) ? $output['filters'] : [];
        $lines = new ToolResultContentListSummary()->header_lines($output);

        if ([] === $items) {
            $lines[] = __('No matching items were returned in this page.', 'agent-wordpress-terminal');

            return implode("\n", $lines);
        }

        $lines = array_merge($lines, $this->item_lines($items, $filters, $output));

        return implode("\n", $lines);
    }

    /**
     * @param list<array<array-key, mixed>> $items
     * @param array<array-key, mixed> $filters
     * @return list<string>
     */
    private function item_lines(array $items, array $filters, array $output): array
    {
        $lines = [
            sprintf(
                /* translators: 1: number of listed items, 2: sort field, 3: sort direction */
                __('Showing %1$d items sorted by %2$s %3$s:', 'agent-wordpress-terminal'),
                count($items),
                (string) ($filters['orderby'] ?? 'modified'),
                (string) ($filters['order'] ?? 'DESC'),
            ),
        ];

        foreach (array_slice($items, 0, 10) as $item) {
            $lines[] = $this->format_item_line($item);
        }

        if (true === ($output['has_more'] ?? false)) {
            $lines[] = sprintf(
                /* translators: %d: remaining item count */
                __('…and %d more. Increase offset to paginate.', 'agent-wordpress-terminal'),
                max(0, (int) ($output['total'] ?? 0) - (int) ($filters['offset'] ?? 0) - count($items)),
            );
        }

        return $lines;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private function format_item_line(array $item): string
    {
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
}
