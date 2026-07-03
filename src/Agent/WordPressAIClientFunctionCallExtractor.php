<?php

/**
 * Extracts function calls from WordPress AI Client results.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Normalizes WordPress AI Client function calls for ProviderRuntime.
 */
final class WordPressAIClientFunctionCallExtractor
{
    /**
     * Extract provider tool calls in the shape expected by ProviderRuntime.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extract(mixed $result): array
    {
        if (!is_object($result) || !method_exists($result, 'getCandidates')) {
            return [];
        }

        $raw_tool_calls = [];
        $candidates = call_user_func([$result, 'getCandidates']);

        if (!is_iterable($candidates)) {
            return [];
        }

        foreach ($candidates as $candidate) {
            $raw_tool_calls = array_merge($raw_tool_calls, $this->extract_from_candidate($candidate));
        }

        return $raw_tool_calls;
    }

    /**
     * Extract tool calls from a single result candidate.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extract_from_candidate(mixed $candidate): array
    {
        if (!is_object($candidate) || !method_exists($candidate, 'getMessage')) {
            return [];
        }

        $message = call_user_func([$candidate, 'getMessage']);

        if (!is_object($message) || !method_exists($message, 'getParts')) {
            return [];
        }

        $raw_tool_calls = [];

        foreach (call_user_func([$message, 'getParts']) as $part) {
            $call = $this->extract_function_call_from_part($part);

            if (null !== $call) {
                $raw_tool_calls[] = $call;
            }
        }

        return $raw_tool_calls;
    }

    /**
     * Normalize a function-call part into ProviderRuntime shape.
     *
     * @return array<string, mixed>|null
     */
    private function extract_function_call_from_part(mixed $part): ?array
    {
        if (!is_object($part) || !method_exists($part, 'getFunctionCall')) {
            return null;
        }

        $function_call = call_user_func([$part, 'getFunctionCall']);

        if (!is_object($function_call)) {
            return null;
        }

        $name = method_exists($function_call, 'getName') ? (string) call_user_func([$function_call, 'getName']) : '';
        $id = method_exists($function_call, 'getId') ? (string) call_user_func([$function_call, 'getId']) : '';
        $args = method_exists($function_call, 'getArgs') ? call_user_func([$function_call, 'getArgs']) : null;

        if ('' === $name) {
            return null;
        }

        return [
            'id' => $id,
            'function' => [
                'name' => $name,
                'arguments' => $this->encode_tool_arguments($args),
            ],
        ];
    }

    /**
     * Encode provider tool arguments as a JSON object string.
     *
     * @param mixed $args Raw provider arguments.
     */
    private function encode_tool_arguments(mixed $args): string
    {
        if (!is_array($args) || [] === $args) {
            return '{}';
        }

        $encoded = wp_json_encode($args);

        return is_string($encoded) ? $encoded : '{}';
    }
}
