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
 * Normalizes WP AI Client results for ProviderRuntime.
 */
final class WordPressAIClientResultParser {
    public function parse(mixed $result): array {
        if (is_wp_error($result)) {
            return [
                'content' => $this->provider_error_content($result),
                'raw_tool_calls' => [],
                'model' => '',
            ];
        }

        $raw_tool_calls = $this->extract_function_calls($result);
        $content = [] === $raw_tool_calls ? $this->extract_content($result) : '';

        if ([] === $raw_tool_calls && '' !== trim($content)) {
            $embedded = $this->extract_embedded_tool_calls($content);
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
     * @return array<int, array<string, mixed>>
     */

    private function extract_function_calls(mixed $result): array {
        if (!is_object($result) || !method_exists($result, 'getCandidates')) {
            return [];
        }

        $raw_tool_calls = [];
        $candidates = $result->getCandidates();

        if (!is_iterable($candidates)) {
            return [];
        }

        foreach ($candidates as $candidate) {
            if (!is_object($candidate) || !method_exists($candidate, 'getMessage')) {
                continue;
            }

            $message = $candidate->getMessage();

            if (!is_object($message) || !method_exists($message, 'getParts')) {
                continue;
            }

            foreach ($message->getParts() as $part) {
                $call = $this->extract_function_call_from_part($part);

                if (null !== $call) {
                    $raw_tool_calls[] = $call;
                }
            }
        }

        return $raw_tool_calls;
    }

    /**
     * @return array<string, mixed>|null
     */

    private function extract_function_call_from_part(mixed $part): ?array {
        if (!is_object($part) || !method_exists($part, 'getFunctionCall')) {
            return null;
        }

        $function_call = $part->getFunctionCall();

        if (!is_object($function_call)) {
            return null;
        }

        $name = method_exists($function_call, 'getName') ? (string) $function_call->getName() : '';
        $id = method_exists($function_call, 'getId') ? (string) $function_call->getId() : '';
        $args = method_exists($function_call, 'getArgs') ? $function_call->getArgs() : null;

        if ('' === $name) {
            return null;
        }

        $encoded = is_array($args) && [] !== $args ? wp_json_encode($args) : '{}';

        return [
            'id' => $id,
            'function' => [
                'name' => $name,
                'arguments' => is_string($encoded) ? $encoded : '{}',
            ],
        ];
    }

    /**
     * @return array{raw_tool_calls: array<int, array<string, mixed>>, content: string}
     */

    private function extract_embedded_tool_calls(string $content): array {
        $raw_tool_calls = [];
        $cleaned = $content;

        $matches = [];
        if (preg_match_all('/<tool_call>\s*(\{.*?\})\s*<\/tool_call>/s', $cleaned, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $decoded = json_decode($match[1] ?? '', true);

                if (!is_array($decoded)) {
                    continue;
                }

                $arguments = is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : [];
                /** @var array<string, mixed> $typed_arguments */
                $typed_arguments = [];

                foreach ($arguments as $key => $value) {
                    if (is_string($key)) {
                        $typed_arguments[$key] = $value;
                    }
                }

                $call = $this->normalize_embedded_call((string) ($decoded['name'] ?? ''), $typed_arguments);

                if (null !== $call) {
                    $raw_tool_calls[] = $call;
                }
            }

            $cleaned = trim((string) preg_replace('/<tool_call>\s*\{.*?\}\s*<\/tool_call>/s', '', $cleaned));
        }

        $matches = [];
        if (preg_match_all('/<((?:awpt|core)\/[\w-]+)\s*\/?>(?:<\/\1>)?/i', $cleaned, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $call = $this->normalize_embedded_call($match[1] ?? '', []);

                if (null !== $call) {
                    $raw_tool_calls[] = $call;
                }
            }

            $cleaned = trim((string) preg_replace('/<((?:awpt|core)\/[\w-]+)\s*\/?>(?:<\/\1>)?/i', '', $cleaned));
        }

        return [
            'raw_tool_calls' => $raw_tool_calls,
            'content' => trim($cleaned),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */

    private function normalize_embedded_call(string $name, array $arguments): ?array {
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

    private function extract_content(mixed $result): string {
        if (is_string($result)) {
            return $result;
        }

        if (!is_object($result)) {
            return '';
        }

        if (method_exists($result, 'toText')) {
            try {
                return (string) $result->toText();
            } catch (\Throwable) {
                return '';
            }
        }

        if (method_exists($result, 'get_text')) {
            return (string) $result->get_text();
        }

        return '';
    }

    private function extract_model(mixed $result): string {
        if (!is_object($result) || !method_exists($result, 'getModelMetadata')) {
            return '';
        }

        $metadata = $result->getModelMetadata();

        if (!is_object($metadata) || !method_exists($metadata, 'getId')) {
            return '';
        }

        return (string) $metadata->getId();
    }

    private function provider_error_content(\WP_Error $error): string {
        $message = $error->get_error_message();

        if ($this->is_text_generation_model_error_message($message)) {
            return __(
                'The selected AI connector could not find a text-generation model for this request. Check Settings > Connectors or choose another provider in AWPT settings.',
                'agent-wordpress-terminal',
            );
        }

        if ((bool) preg_match('/no text content found|invalid schema|bad request/i', $message)) {
            return '';
        }

        return $message;
    }

    public function is_text_generation_model_error(mixed $result): bool {
        if (!is_wp_error($result)) {
            return false;
        }

        return $this->is_text_generation_model_error_message($result->get_error_message());
    }

    private function is_text_generation_model_error_message(string $message): bool {
        return (bool) preg_match('/no models found.*text_generation|support text_generation/i', $message);
    }
}
