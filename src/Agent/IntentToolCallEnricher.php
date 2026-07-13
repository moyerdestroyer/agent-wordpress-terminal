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
 * Narrow recovery when the model returns no structured tools but clearly deferred.
 *
 * Only covers settings-read and list-content intents. Content search is left to the
 * model/tools path so broad keyword heuristics do not inject tools on every page edit.
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

        if ('' === $last_user_message || !$this->should_auto_invoke_tool($content)) {
            return $result;
        }

        if ($this->should_auto_read_settings($last_user_message)) {
            $site_info_ability = $tool_registry->preferred_site_info_ability();

            if (null !== $site_info_ability) {
                return $this->with_tool_call($result, $tool_registry, $site_info_ability, '{}');
            }
        }

        if (
            !$this->looks_like_new_content_request(strtolower($last_user_message))
            && $this->should_auto_list($last_user_message)
        ) {
            $arguments = wp_json_encode($this->list_arguments_for_message($last_user_message));

            return $this->with_tool_call(
                $result,
                $tool_registry,
                'awpt/list-content',
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
     * Auto-invoke only when the model returned empty text or deferred/failed tool-call prose.
     */
    private function should_auto_invoke_tool(string $content): bool {
        $trimmed = trim($content);

        if ('' === $trimmed) {
            return true;
        }

        return $this->is_deferred_intent_content($content) || $this->is_provider_failure_content($content);
    }

    private function is_deferred_intent_content(string $content): bool {
        return (bool) preg_match('/\b(i(?:[\'\x{2019}]ll| will))\s+(check|look|read|fetch|review)\b/iu', $content);
    }

    private function is_provider_failure_content(string $content): bool {
        return (bool) preg_match(
            '/no text content found|invalid schema|bad request|abilities api is not available/i',
            $content,
        );
    }

    /**
     * Clear settings/site-info requests only (not every mention of "theme" or "comment").
     */
    private function should_auto_read_settings(string $message): bool {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'setting') || str_contains($normalized, 'site url')) {
            return true;
        }

        if (preg_match('/\b(site\s+)?(info|configuration|options)\b/', $normalized)) {
            return true;
        }

        return (bool) preg_match('/\b(comment|discussion)\s+settings?\b/', $normalized);
    }

    private function looks_like_new_content_request(string $normalized): bool {
        return (bool) preg_match('/\b(create|draft|write|make|add)\s+(a\s+)?new\s+(post|page|article)\b/', $normalized);
    }

    private function should_auto_list(string $message): bool {
        $normalized = strtolower($message);

        if ($this->looks_like_specific_content_lookup($normalized)) {
            return false;
        }

        if (!preg_match(
            '/\b('
            . 'how many|how much|count|number of|total|list|show|recent|latest|browse|inventory|'
            . 'what posts|what pages|authored by|written by|by author|my posts|my pages|drafts'
            . ')\b/',
            $normalized,
        )) {
            return false;
        }

        foreach (['post', 'page', 'content', 'article', 'draft', 'template', 'block', 'author'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return (bool) preg_match(
            '/\b(how many|count|number of|total|list|show|recent)\b.*\b(site|wordpress|blog)\b/',
            $normalized,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function list_arguments_for_message(string $message): array {
        $normalized = strtolower($message);
        $arguments = ['limit' => 20];

        if (str_contains($normalized, 'page') && !str_contains($normalized, 'post')) {
            $arguments['post_type'] = 'page';
        } elseif (str_contains($normalized, 'template')) {
            $arguments['post_type'] = 'wp_template';
        } elseif (str_contains($normalized, 'block')) {
            $arguments['post_type'] = 'wp_block';
        } elseif (str_contains($normalized, 'attachment') || str_contains($normalized, 'media')) {
            $arguments['post_type'] = 'attachment';
        } elseif (
            str_contains($normalized, 'content')
            && !str_contains($normalized, 'post')
            && !str_contains($normalized, 'page')
        ) {
            $arguments['post_type'] = 'all';
        } else {
            $arguments['post_type'] = 'post';
        }

        if (str_contains($normalized, 'draft')) {
            $arguments['status'] = 'draft';
        }

        if (preg_match('/\b(authored by|written by|by author|my posts|my pages)\b/', $normalized)) {
            $arguments['author'] = (string) get_current_user_id();
        }

        if (preg_match('/\b(recent|latest|newest)\b/', $normalized)) {
            $arguments['orderby'] = 'date';
        }

        if (preg_match('/\b(alphabet|title)\b/', $normalized)) {
            $arguments['orderby'] = 'title';
            $arguments['order'] = 'ASC';
        }

        return $arguments;
    }

    private function looks_like_specific_content_lookup(string $normalized): bool {
        if (preg_match('/\b(list|show all|how many|count|recent|latest|browse|inventory)\b/', $normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(find|locate|open|edit|update|change|preview|focus on|read|look at)\b/',
            $normalized,
        );
    }
}
