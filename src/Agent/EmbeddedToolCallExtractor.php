<?php

/**
 * Extracts tool calls embedded in assistant text.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Some WordPress AI connectors return ability calls as plain text instead of structured function calls.
 */
final class EmbeddedToolCallExtractor
{
    /**
     * Extract embedded tool calls and return cleaned assistant text.
     *
     * @return array{raw_tool_calls: array<int, array<string, mixed>>, content: string}
     */
    public function extract(string $content): array
    {
        $raw_tool_calls = [];
        $cleaned = $content;

        $raw_tool_calls = array_merge($raw_tool_calls, $this->extract_json_tool_calls($cleaned, $cleaned));
        $raw_tool_calls = array_merge($raw_tool_calls, $this->extract_ability_tags($cleaned, $cleaned));

        return [
            'raw_tool_calls' => $raw_tool_calls,
            'content' => trim($cleaned),
        ];
    }

    /**
     * Parse <tool_call>{...}</tool_call> blocks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extract_json_tool_calls(string $content, string &$cleaned): array
    {
        $raw_tool_calls = [];

        $matches = [];

        if (!preg_match_all('/<tool_call>\s*(\{.*?\})\s*<\/tool_call>/s', $content, $matches, PREG_SET_ORDER)) {
            return $raw_tool_calls;
        }

        foreach ($matches as $match) {
            $decoded = json_decode($match[1] ?? '', true);

            if (!is_array($decoded)) {
                continue;
            }

            $call = $this->normalize_call(
                (string) ($decoded['name'] ?? ''),
                is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : [],
            );

            if (null !== $call) {
                $raw_tool_calls[] = $call;
            }
        }

        $cleaned = trim((string) preg_replace('/<tool_call>\s*\{.*?\}\s*<\/tool_call>/s', '', $cleaned));

        return $raw_tool_calls;
    }

    /**
     * Parse <awpt/read-settings /> style ability tags.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extract_ability_tags(string $content, string &$cleaned): array
    {
        $raw_tool_calls = [];

        $matches = [];

        if (!preg_match_all('/<((?:awpt|core)\/[\w-]+)\s*\/?>(?:<\/\1>)?/i', $content, $matches, PREG_SET_ORDER)) {
            return $raw_tool_calls;
        }

        foreach ($matches as $match) {
            $call = $this->normalize_call($match[1] ?? '', []);

            if (null !== $call) {
                $raw_tool_calls[] = $call;
            }
        }

        $cleaned = trim((string) preg_replace('/<((?:awpt|core)\/[\w-]+)\s*\/?>(?:<\/\1>)?/i', '', $cleaned));

        return $raw_tool_calls;
    }

    /**
     * Normalize a tool or ability name into ProviderRuntime shape.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @return array<string, mixed>|null
     */
    private function normalize_call(string $name, array $arguments): ?array
    {
        $registry = new ToolRegistry();
        $function_name = $registry->function_name_for_ability($name);

        if (null === $function_name || null === $registry->tool_name_for_function($function_name)) {
            return null;
        }

        return [
            'id' => 'awpt_embedded_' . wp_generate_password(8, false),
            'function' => [
                'name' => $function_name,
                'arguments' => wp_json_encode($arguments),
            ],
        ];
    }
}
