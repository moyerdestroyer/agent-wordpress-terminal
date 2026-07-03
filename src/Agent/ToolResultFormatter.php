<?php

/**
 * Formats tool outputs for transcript fallback responses.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

defined('ABSPATH') || exit();

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
            if (!is_array($tool_call) || 'success' !== (string) ($tool_call['status'] ?? '')) {
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

        return match ($tool) {
            'awpt/read-settings' => $this->format_read_settings($output),
            default => $this->format_generic_tool($tool, $output),
        };
    }

    /**
     * Format read-settings output.
     *
     * @param array<string, mixed> $output Tool output.
     */
    private function format_read_settings(array $output): string
    {
        $site = is_array($output['site'] ?? null) ? $output['site'] : [];
        $theme = is_array($output['theme'] ?? null) ? $output['theme'] : [];
        $discussion = is_array($output['discussion'] ?? null) ? $output['discussion'] : [];
        $comments_open = 'open' === (string) ($discussion['default_comment_status'] ?? '');

        $lines = [
            __('Here are the non-secret site settings I found:', 'agent-wordpress-terminal'),
            sprintf(
                /* translators: %s: site URL */
                __('- Site URL: %s', 'agent-wordpress-terminal'),
                (string) ($site['url'] ?? ''),
            ),
            sprintf(
                /* translators: %s: site name */
                __('- Site name: %s', 'agent-wordpress-terminal'),
                (string) ($site['name'] ?? ''),
            ),
        ];

        if ([] !== $theme) {
            $lines[] = sprintf(
                /* translators: %s: theme name */
                __('- Active theme: %s', 'agent-wordpress-terminal'),
                (string) ($theme['name'] ?? ''),
            );

            if ('' !== (string) ($theme['version'] ?? '')) {
                $lines[] = sprintf(
                    /* translators: %s: theme version */
                    __('- Theme version: %s', 'agent-wordpress-terminal'),
                    (string) $theme['version'],
                );
            }

            if ('' !== (string) ($theme['stylesheet'] ?? '')) {
                $lines[] = sprintf(
                    /* translators: %s: theme stylesheet slug */
                    __('- Theme stylesheet: %s', 'agent-wordpress-terminal'),
                    (string) $theme['stylesheet'],
                );
            }
        }

        if ([] !== $discussion) {
            $lines[] = sprintf(
                /* translators: %s: open or closed */
                __('- New posts allow comments: %s', 'agent-wordpress-terminal'),
                $comments_open
                    ? __('yes (open)', 'agent-wordpress-terminal')
                    : __('no (closed)', 'agent-wordpress-terminal'),
            );
        }

        return implode("\n", $lines);
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
