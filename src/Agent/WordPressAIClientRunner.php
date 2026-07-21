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
        $builder = call_user_func('wp_ai_client_prompt', $prompt);
        $builder = $this->configure_builder(
            $builder,
            $system_instruction,
            $connector_id,
            $ability_names,
            $max_completion_tokens,
        );

        if ([] !== $ability_names && !$this->builder_supports_text_generation($builder)) {
            $builder = call_user_func('wp_ai_client_prompt', $prompt);
            $builder = $this->configure_builder(
                $builder,
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
            $builder = call_user_func('wp_ai_client_prompt', $prompt);
            $builder = $this->configure_builder(
                $builder,
                $system_instruction,
                $connector_id,
                [],
                $max_completion_tokens,
            );
            $result = $this->generate_result($builder);
        }

        if (is_wp_error($result)) {
            $this->log_provider_error($connector_id, $result, 'final generation result');
        }

        $parser = new WordPressAIClientResultParser();
        $parsed = $parser->parse($result);
        $parsed['no_text_generation_model'] = $parser->is_text_generation_model_error($result);

        return $parsed;
    }

    /**
     * @return array{content: string, raw_tool_calls: array<int, array<string, mixed>>, model: string}
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

    /**
     * @param list<string> $ability_names
     */
    private function configure_builder(
        mixed $builder,
        string $system_instruction,
        string $connector_id,
        array $ability_names,
        int $max_completion_tokens,
    ): mixed {
        if (!is_object($builder)) {
            return $builder;
        }

        $configured_result = $builder->using_max_tokens(max(1024, min(32_000, $max_completion_tokens)));
        $configured = is_object($configured_result) ? $configured_result : $builder;

        if ('' !== $connector_id && method_exists($configured, 'using_provider')) {
            $provider_result = $configured->using_provider($connector_id);
            $configured = is_object($provider_result) ? $provider_result : $configured;
        }

        $configured = $this->apply_abilities($configured, $ability_names);

        if ('' === $system_instruction) {
            return $configured;
        }

        $system_result = $configured->using_system_instruction($system_instruction);

        return is_object($system_result) ? $system_result : $configured;
    }

    /**
     * @param list<string> $ability_names
     */
    private function apply_abilities(object $builder, array $ability_names): object {
        if ([] === $ability_names) {
            return $builder;
        }

        if (
            class_exists('WordPress\AiClient\Tools\DTO\FunctionDeclaration')
            && method_exists($builder, 'using_function_declarations')
        ) {
            $declarations = WordPressAIClientProvider::build_function_declarations($ability_names);

            if ([] !== $declarations) {
                $configured = $builder->using_function_declarations(...$declarations);

                return is_object($configured) ? $configured : $builder;
            }
        }

        if (method_exists($builder, 'using_abilities')) {
            $configured = $builder->using_abilities(...$ability_names);

            return is_object($configured) ? $configured : $builder;
        }

        return $builder;
    }

    private function generate_result(mixed $builder): mixed {
        if (!is_object($builder)) {
            return '';
        }

        if (is_callable([$builder, 'generate_text_result'])) {
            try {
                return $builder->generate_text_result();
            } catch (\Throwable $error) {
                return new \WP_Error('awpt_provider_generation_failed', $error->getMessage());
            }
        }

        if (is_callable([$builder, 'generate_text'])) {
            try {
                return $builder->generate_text();
            } catch (\Throwable $error) {
                return new \WP_Error('awpt_provider_generation_failed', $error->getMessage());
            }
        }

        return '';
    }

    private function builder_supports_text_generation(mixed $builder): bool {
        if (!is_object($builder) || !is_callable([$builder, 'is_supported_for_text_generation'])) {
            return true;
        }

        try {
            return (bool) $builder->is_supported_for_text_generation();
        } catch (\Throwable) {
            return true;
        }
    }

    private function log_provider_error(string $connector_id, mixed $result, string $context): void {
        if (!is_wp_error($result) || !defined('WP_DEBUG') || !\WP_DEBUG) {
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
