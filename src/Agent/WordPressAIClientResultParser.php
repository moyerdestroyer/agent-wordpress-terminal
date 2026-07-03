<?php

/**
 * Parses WordPress AI Client generation results.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Normalizes WordPress AI Client result objects for ProviderRuntime.
 */
final class WordPressAIClientResultParser
{
    /**
     * Function call extractor.
     */
    private WordPressAIClientFunctionCallExtractor $function_calls;

    /**
     * @param WordPressAIClientFunctionCallExtractor|null $function_calls Optional extractor for testing.
     */
    public function __construct(?WordPressAIClientFunctionCallExtractor $function_calls = null)
    {
        $this->function_calls = $function_calls ?? new WordPressAIClientFunctionCallExtractor();
    }

    /**
     * Parse a provider result into AWPT's normalized response shape.
     *
     * @return array{content: string, raw_tool_calls: array<int, array<string, mixed>>, model: string}
     */
    public function parse(mixed $result): array
    {
        if (is_wp_error($result)) {
            return [
                'content' => $this->provider_error_content($result),
                'raw_tool_calls' => [],
                'model' => '',
            ];
        }

        $raw_tool_calls = $this->function_calls->extract($result);
        $content = [] === $raw_tool_calls ? $this->extract_content($result) : '';

        if ([] === $raw_tool_calls && '' !== trim($content)) {
            $embedded = new EmbeddedToolCallExtractor()->extract($content);
            $raw_tool_calls = $embedded['raw_tool_calls'];
            $content = $embedded['content'];
        }

        return [
            'content' => $content,
            'raw_tool_calls' => $raw_tool_calls,
            'model' => $this->extract_model($result),
        ];
    }

    /**
     * Extract assistant text from a provider result.
     */
    private function extract_content(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        if (!is_object($result)) {
            return '';
        }

        if (method_exists($result, 'toText')) {
            try {
                return (string) call_user_func([$result, 'toText']);
            } catch (\Throwable) {
                return '';
            }
        }

        if (method_exists($result, 'get_text')) {
            return (string) call_user_func([$result, 'get_text']);
        }

        return '';
    }

    /**
     * Extract model metadata from a provider result.
     */
    private function extract_model(mixed $result): string
    {
        if (!is_object($result) || !method_exists($result, 'getModelMetadata')) {
            return '';
        }

        $metadata = call_user_func([$result, 'getModelMetadata']);

        if (!is_object($metadata) || !method_exists($metadata, 'getId')) {
            return '';
        }

        return (string) call_user_func([$metadata, 'getId']);
    }

    /**
     * Normalize provider WP_Error messages for downstream recovery.
     */
    private function provider_error_content(\WP_Error $error): string
    {
        $message = $error->get_error_message();

        if ($this->is_recoverable_provider_error($message)) {
            return '';
        }

        return $message;
    }

    /**
     * Whether a provider error can be recovered via local tool execution.
     */
    private function is_recoverable_provider_error(string $message): bool
    {
        return (bool) preg_match('/no text content found|invalid schema|bad request/i', $message);
    }
}
