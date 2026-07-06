<?php

/**
 * Formats tool outputs for transcript fallback responses.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable assistant text when provider follow-up is empty.
 */
final class ToolResultFormatter
{
    /**
     * Format successful tool calls into assistant-visible text.
     *
     * @param array<int, array<string, mixed>> $tool_calls Executed tool calls.
     * @param string                           $prefix Optional assistant prefix text.
     */
    public function format_for_transcript(array $tool_calls, string $prefix = ''): string
    {
        $sections = [];

        foreach ($tool_calls as $tool_call) {
            if ('success' !== (string) ($tool_call['status'] ?? '')) {
                continue;
            }

            $section = $this->format_tool_call($tool_call);

            if ('' !== $section) {
                $sections[] = $section;
            }
        }

        if ([] === $sections) {
            return trim($prefix);
        }

        $body = implode("\n\n", $sections);

        if ('' === trim($prefix)) {
            return $body;
        }

        return trim($prefix) . "\n\n" . $body;
    }

    /**
     * Format one tool call.
     *
     * @param array<string, mixed> $tool_call Tool call record.
     */
    private function format_tool_call(array $tool_call): string
    {
        $tool = (string) ($tool_call['tool'] ?? '');
        $output = is_array($tool_call['output'] ?? null) ? $tool_call['output'] : [];

        if ('awpt/knowledge-auto-retrieval' === $tool) {
            return new ToolResultKnowledgeFormatter()->format($output);
        }

        if ('awpt/search-content' === $tool) {
            return new ToolResultContentSearchFormatter()->format($output);
        }

        if ('awpt/list-content' === $tool) {
            return new ToolResultContentListFormatter()->format($output);
        }

        if (in_array($tool, ['awpt/read-content', 'awpt/read-block-tree'], true)) {
            return new ToolResultContentReadFormatter()->format($tool, $output);
        }

        if ('awpt/read-settings' === $tool) {
            return new ToolResultSettingsFormatter()->format($output);
        }

        if (ToolRegistry::is_proposal_ability($tool)) {
            return new ToolResultProposalFormatter()->format($tool, $output);
        }

        return $this->format_generic_tool($tool, $output);
    }

    /**
     * Format generic tool output.
     *
     * @param array<string, mixed> $output Tool output.
     */
    private function format_generic_tool(string $tool, array $output): string
    {
        return sprintf(
            /* translators: 1: tool name, 2: JSON output */
            __('Tool %1$s returned: %2$s', 'agent-wordpress-terminal'),
            $tool,
            wp_json_encode($output),
        );
    }
}
