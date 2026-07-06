<?php

/**
 * Formats automatic Knowledge retrieval evidence.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable summaries for automatic Knowledge context.
 */
final class ToolResultKnowledgeFormatter
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

        if ([] === $results) {
            return __('No automatic Knowledge excerpts were added to context.', 'agent-wordpress-terminal');
        }

        $lines = [__('Automatic Knowledge context added:', 'agent-wordpress-terminal')];

        foreach (array_slice($results, 0, 5) as $result) {
            $lines[] = sprintf(
                /* translators: 1: source label, 2: source kind */
                __('- %1$s (%2$s)', 'agent-wordpress-terminal'),
                (string) ($result['label'] ?? ''),
                (string) ($result['source_kind'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }
}
