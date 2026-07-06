<?php

/**
 * Chat prompt formatting helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Formats chat messages for the WordPress AI Client prompt builder.
 */
final class ChatPromptFormatter {
    /**
     * Extract the system instruction from chat messages.
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     */
    public function extract_system_instruction(array $messages): string {
        foreach ($messages as $message) {
            if ('system' === (string) ($message['role'] ?? '')) {
                return $this->stringify_content($message['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Flatten non-system chat history for the prompt builder.
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     */
    public function build_prompt_text(array $messages): string {
        $lines = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');

            if ('system' === $role) {
                continue;
            }

            if ('tool' === $role) {
                $tool_call_id = (string) ($message['tool_call_id'] ?? 'tool');
                $content = $this->stringify_content($message['content'] ?? '');
                $lines[] = sprintf('Tool result (%s): %s', $tool_call_id, $content);

                continue;
            }

            if ('assistant' === $role && is_array($message['tool_calls'] ?? null) && [] !== $message['tool_calls']) {
                $lines[] = 'Assistant tool calls: ' . wp_json_encode($message['tool_calls']);

                $content = $this->stringify_content($message['content'] ?? '');

                if ('' !== $content) {
                    $lines[] = sprintf('Assistant: %s', $content);
                }

                continue;
            }

            $content = $this->stringify_content($message['content'] ?? '');

            if ('' === $content) {
                continue;
            }

            $label = '' !== $role ? $role : 'message';
            $lines[] = sprintf('%s: %s', ucfirst($label), $content);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Normalize message content.
     *
     * @param mixed $content Raw content.
     */
    private function stringify_content(mixed $content): string {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $part) {
            if (!is_array($part) || !is_string($part['text'] ?? null)) {
                continue;
            }

            $parts[] = $part['text'];
        }

        return implode("\n", $parts);
    }
}
