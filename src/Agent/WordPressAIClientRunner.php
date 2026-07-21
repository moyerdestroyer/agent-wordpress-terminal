<?php

/**
 * Runs and parses WordPress AI Client prompts.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Prompt generation + result parsing for the optional WP AI Client path.
 */
final class WordPressAIClientRunner {
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param list<string>                     $ability_names
     * @return array{content: string, raw_tool_calls: array<int, array<string, mixed>>, model: string, no_text_generation_model: bool}
     */
    public function generate(
        array $messages,
        string $connector_id,
        array $ability_names = [],
        int $max_completion_tokens = 8192,
    ): array {
        $system_instruction = $this->extract_system_instruction($messages);
        $prompt = $this->build_prompt_text($messages);
        $builder = $this->new_builder($prompt);

        if (null === $builder) {
            return $this->empty_generation(true);
        }

        $builder = $this->configure_builder(
            $builder,
            $system_instruction,
            $connector_id,
            $ability_names,
            $max_completion_tokens,
        );

        if ([] !== $ability_names && !$this->builder_supports_text_generation($builder)) {
            $fallback = $this->new_builder($prompt);

            if (null === $fallback) {
                return $this->empty_generation(true);
            }

            $builder = $this->configure_builder(
                $fallback,
                $system_instruction,
                $connector_id,
                [],
                $max_completion_tokens,
            );
            $ability_names = [];
        }

        $result = $this->generate_result($builder);

        if ([] !== $ability_names && new WordPressAIClientResultParser()->is_text_generation_model_error($result)) {
            $this->log_provider_error($connector_id, $result, 'pre-flight check passed but generation still failed');
            $fallback = $this->new_builder($prompt);

            if (null !== $fallback) {
                $builder = $this->configure_builder(
                    $fallback,
                    $system_instruction,
                    $connector_id,
                    [],
                    $max_completion_tokens,
                );
                $result = $this->generate_result($builder);
            }
        }

        if (is_wp_error($result)) {
            $this->log_provider_error($connector_id, $result, 'final generation result');
        }

        $parser = new WordPressAIClientResultParser();
        $parsed = $parser->parse($result);

        return [
            'content' => $parsed['content'],
            'raw_tool_calls' => $parsed['raw_tool_calls'],
            'model' => $parsed['model'],
            'no_text_generation_model' => $parser->is_text_generation_model_error($result),
        ];
    }

    private function new_builder(string $prompt): ?\AWPT_AI_Prompt_Builder {
        if (!function_exists('wp_ai_client_prompt')) {
            return null;
        }

        $builder = call_user_func('wp_ai_client_prompt', $prompt);

        if ($builder instanceof \AWPT_AI_Prompt_Builder) {
            return $builder;
        }

        return is_object($builder) ? new AiPromptBuilderAdapter($builder) : null;
    }

    /**
     * @return array{content: string, raw_tool_calls: array<int, array<string, mixed>>, model: string, no_text_generation_model: bool}
     */
    private function empty_generation(bool $no_text_generation_model): array {
        return [
            'content' => '',
            'raw_tool_calls' => [],
            'model' => '',
            'no_text_generation_model' => $no_text_generation_model,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function extract_system_instruction(array $messages): string {
        foreach ($messages as $message) {
            if ('system' === (string) ($message['role'] ?? '')) {
                return $this->stringify_content($message['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function build_prompt_text(array $messages): string {
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
                $encoded = wp_json_encode($message['tool_calls']);
                $lines[] = 'Assistant tool calls: ' . (is_string($encoded) ? $encoded : '[]');
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

    private function stringify_content(mixed $content): string {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach (array_keys($content) as $key) {
            $part = \AWPT\Support\ArrayKey::as_map_or_null(\AWPT\Support\ArrayKey::passthrough($content[$key] ?? null));

            if (null === $part) {
                continue;
            }

            $text = \AWPT\Support\ArrayKey::as_string($part['text'] ?? null);

            if (null !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param list<string> $ability_names
     */
    private function configure_builder(
        \AWPT_AI_Prompt_Builder $builder,
        string $system_instruction,
        string $connector_id,
        array $ability_names,
        int $max_completion_tokens,
    ): \AWPT_AI_Prompt_Builder {
        $configured = $builder->using_max_tokens(max(1024, min(32_000, $max_completion_tokens)));

        if ('' !== $connector_id) {
            $configured = $configured->using_provider($connector_id);
        }

        $configured = $this->apply_abilities($configured, $ability_names);

        if ('' === $system_instruction) {
            return $configured;
        }

        return $configured->using_system_instruction($system_instruction);
    }

    /**
     * @param list<string> $ability_names
     */
    private function apply_abilities(\AWPT_AI_Prompt_Builder $builder, array $ability_names): \AWPT_AI_Prompt_Builder {
        if ([] === $ability_names) {
            return $builder;
        }

        if (class_exists('WordPress\AiClient\Tools\DTO\FunctionDeclaration')) {
            $declarations = WordPressAIClientProvider::build_function_declarations($ability_names);

            if ([] !== $declarations) {
                return $builder->using_function_declarations(...$declarations);
            }
        }

        return $builder->using_abilities(...$ability_names);
    }

    private function generate_result(\AWPT_AI_Prompt_Builder $builder): mixed {
        try {
            return $builder->generate_text_result();
        } catch (\Throwable $error) {
            try {
                return $builder->generate_text();
            } catch (\Throwable $text_error) {
                return new \WP_Error(
                    'awpt_provider_generation_failed',
                    $text_error->getMessage() !== '' ? $text_error->getMessage() : $error->getMessage(),
                );
            }
        }
    }

    private function builder_supports_text_generation(\AWPT_AI_Prompt_Builder $builder): bool {
        try {
            return $builder->is_supported_for_text_generation();
        } catch (\Throwable) {
            return true;
        }
    }

    private function log_provider_error(string $connector_id, mixed $result, string $context): void {
        if (!is_wp_error($result) || !defined('WP_DEBUG') || true !== constant('WP_DEBUG')) {
            return;
        }

        error_log(sprintf(
            '[AWPT] WordPress AI Client error (%s, connector "%s", %s): %s',
            $result->get_error_code(),
            $connector_id,
            $context,
            $result->get_error_message(),
        ));
    }
}
