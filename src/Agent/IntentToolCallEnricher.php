<?php

/**
 * Adds tool calls when models defer work in plain text.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Detects settings-related requests that the model answered with deferred intent text.
 */
final class IntentToolCallEnricher {
    /**
     * Add tool calls when the model defers work in text instead of invoking abilities.
     *
     * @param array<int, array<string, mixed>> $messages Provider messages.
     * @param array<string, mixed>             $result Provider result.
     * @param ToolRegistry                     $tool_registry Tool registry.
     * @return array<string, mixed>
     */
    public function enrich(array $messages, array $result, ToolRegistry $tool_registry): array {
        $raw_tool_calls = is_array($result['raw_tool_calls'] ?? null) ? $result['raw_tool_calls'] : [];

        if ([] !== $raw_tool_calls) {
            return $result;
        }

        $last_user_message = $this->get_last_user_message($messages);
        $content = (string) ($result['content'] ?? '');

        if (
            '' !== $last_user_message
            && $this->should_auto_read_settings($last_user_message)
            && $this->should_auto_invoke_tool($content)
        ) {
            return $this->with_tool_call($result, $tool_registry, 'awpt/read-settings', '{}');
        }

        $list_detector = new ContentListIntentDetector();

        if (
            '' !== $last_user_message
            && !$this->looks_like_new_content_request(strtolower($last_user_message))
            && $list_detector->should_auto_list($last_user_message)
            && $this->should_auto_invoke_tool($content)
        ) {
            $arguments = wp_json_encode($list_detector->arguments_for_message($last_user_message));

            return $this->with_tool_call(
                $result,
                $tool_registry,
                'awpt/list-content',
                false === $arguments ? '{}' : $arguments,
            );
        }

        if (
            '' !== $last_user_message
            && $this->should_auto_search_content($last_user_message)
            && $this->should_auto_invoke_tool($content)
        ) {
            $arguments = wp_json_encode([
                'query' => $last_user_message,
                'limit' => 5,
            ]);

            return $this->with_tool_call(
                $result,
                $tool_registry,
                'awpt/search-content',
                false === $arguments ? '{}' : $arguments,
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function with_tool_call(
        array $result,
        ToolRegistry $tool_registry,
        string $ability,
        string $arguments,
    ): array {
        $function_name = $tool_registry->function_name_for_ability($ability);

        if (null === $function_name) {
            return $result;
        }

        $result['raw_tool_calls'] = [
            [
                'id' => 'awpt_intent_' . wp_generate_password(8, false),
                'function' => [
                    'name' => $function_name,
                    'arguments' => $arguments,
                ],
            ],
        ];
        $result['content'] = '';
        $result['message'] = [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => $result['raw_tool_calls'],
        ];

        return $result;
    }

    /**
     * Get the latest user message from provider context.
     *
     * @param array<int, array<string, mixed>> $messages Provider messages.
     */
    private function get_last_user_message(array $messages): string {
        for ($index = count($messages) - 1; $index >= 0; --$index) {
            if ('user' === (string) ($messages[$index]['role'] ?? '')) {
                return (string) ($messages[$index]['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Whether AWPT should invoke read-settings without provider tool calls.
     */
    private function should_auto_invoke_tool(string $content): bool {
        $trimmed = trim($content);

        if ('' === $trimmed) {
            return true;
        }

        return $this->is_deferred_intent_content($content) || $this->is_provider_failure_content($content);
    }

    /**
     * Whether assistant text only promises future work.
     */
    private function is_deferred_intent_content(string $content): bool {
        return (bool) preg_match('/\b(i(?:[\'\x{2019}]ll| will))\s+(check|look|read|fetch|review)\b/iu', $content);
    }

    /**
     * Whether provider output indicates a recoverable tool-call failure.
     */
    private function is_provider_failure_content(string $content): bool {
        return (bool) preg_match(
            '/no text content found|invalid schema|bad request|abilities api is not available/i',
            $content,
        );
    }

    /**
     * Whether the user is asking for site settings.
     */
    private function should_auto_read_settings(string $message): bool {
        $normalized = strtolower($message);

        return (
            str_contains($normalized, 'setting')
            || str_contains($normalized, 'site url')
            || str_contains($normalized, 'comments')
            || str_contains($normalized, 'comment')
            || str_contains($normalized, 'theme')
        );
    }

    /**
     * Whether the user is asking for existing content.
     */
    private function should_auto_search_content(string $message): bool {
        $normalized = strtolower($message);

        if ($this->looks_like_new_content_request($normalized)) {
            return false;
        }

        foreach ([
            'page',
            'post',
            'content',
            'block',
            'icon',
            'hero',
            'section',
            'template',
            'about',
            'homepage',
            'home page',
            'landing page',
            'preview',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return (bool) preg_match('/https?:\/\/\S+/i', $message);
    }

    private function looks_like_new_content_request(string $normalized): bool {
        return (bool) preg_match('/\b(create|draft|write|make|add)\s+(a\s+)?new\s+(post|page|article)\b/', $normalized);
    }
}
