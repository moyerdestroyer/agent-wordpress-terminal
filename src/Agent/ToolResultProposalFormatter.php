<?php

/**
 * Formats proposal tool outputs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable summaries for staged action outputs.
 */
final class ToolResultProposalFormatter {
    /**
     * @param array<array-key, mixed> $output
     */
    public function format(string $tool, array $output): string {
        $title = (string) ($output['title'] ?? '');
        $status = (string) ($output['status'] ?? 'proposed');
        $id = (int) ($output['id'] ?? 0);

        return sprintf(
            /* translators: 1: tool name, 2: action ID, 3: action title, 4: status */
            __('%1$s staged action #%2$d: %3$s (%4$s).', 'agent-wordpress-terminal'),
            $tool,
            $id,
            '' !== $title ? $title : __('Untitled action', 'agent-wordpress-terminal'),
            $status,
        );
    }
}
