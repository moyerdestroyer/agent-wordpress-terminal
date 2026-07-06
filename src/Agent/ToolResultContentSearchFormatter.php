<?php

/**
 * Formats content search tool outputs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable summaries for content search results.
 */
final class ToolResultContentSearchFormatter
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
        $results = $this->arrays->list_items($output['results'] ?? []);
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
            $lines[] = $this->format_result_line($result);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private function format_result_line(array $result): string
    {
        return sprintf(
            /* translators: 1: post ID, 2: title, 3: post type, 4: status, 5: match reason */
            __('- #%1$d %2$s (%3$s, %4$s; %5$s)', 'agent-wordpress-terminal'),
            (int) ($result['id'] ?? 0),
            (string) ($result['title'] ?? ''),
            (string) ($result['type'] ?? ''),
            (string) ($result['status'] ?? ''),
            (string) ($result['matched_by'] ?? ''),
        );
    }
}
