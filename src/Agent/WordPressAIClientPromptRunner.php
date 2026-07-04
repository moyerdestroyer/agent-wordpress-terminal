<?php

/**
 * Runs prompts through the optional WordPress AI Client.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Isolates dynamic calls to wp_ai_client_prompt().
 */
final class WordPressAIClientPromptRunner
{
    /**
     * Chat prompt formatter.
     */
    private ChatPromptFormatter $formatter;

    /**
     * Result parser.
     */
    private WordPressAIClientResultParser $parser;

    /**
     * @param ChatPromptFormatter|null           $formatter Optional formatter for testing.
     * @param WordPressAIClientResultParser|null $parser Optional parser for testing.
     */
    public function __construct(?ChatPromptFormatter $formatter = null, ?WordPressAIClientResultParser $parser = null)
    {
        $this->formatter = $formatter ?? new ChatPromptFormatter();
        $this->parser = $parser ?? new WordPressAIClientResultParser();
    }

    /**
     * Generate text for a chat transcript.
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     * @param string                           $connector_id Selected connector ID.
     * @param list<string>                     $ability_names Ability names exposed to the model.
     * @return array{
     *     content: string,
     *     raw_tool_calls: array<int, array<string, mixed>>,
     *     model: string,
     *     no_text_generation_model: bool
     * }
     */
    public function generate(array $messages, string $connector_id, array $ability_names = []): array
    {
        $system_instruction = $this->formatter->extract_system_instruction($messages);
        $prompt = $this->formatter->build_prompt_text($messages);
        $builder = call_user_func('wp_ai_client_prompt', $prompt);
        $builder = $this->configure_builder($builder, $system_instruction, $connector_id, $ability_names);

        // Pre-flight: WordPress Core's model-matching requires a single model that
        // supports every attached capability (text generation *and* function calling).
        // Not every connector/model combination can do both, so check before spending a
        // request on it, rather than only discovering the mismatch via a failed call.
        if ([] !== $ability_names && !$this->builder_supports_text_generation($builder)) {
            $builder = call_user_func('wp_ai_client_prompt', $prompt);
            $builder = $this->configure_builder($builder, $system_instruction, $connector_id, []);
            $ability_names = [];
        }

        $result = $this->generate_result($builder);

        // Safety net: even when the pre-flight check passes (or isn't available on this
        // AI Client version), a single retry without abilities covers any remaining
        // mismatch the check didn't catch.
        if ([] !== $ability_names && $this->is_text_generation_model_error($result)) {
            $this->log_provider_error($connector_id, $result, 'pre-flight check passed but generation still failed');
            $builder = call_user_func('wp_ai_client_prompt', $prompt);
            $builder = $this->configure_builder($builder, $system_instruction, $connector_id, []);
            $result = $this->generate_result($builder);
        }

        if (is_wp_error($result)) {
            $this->log_provider_error($connector_id, $result, 'final generation result');
        }

        $parsed = $this->parser->parse($result);
        $parsed['no_text_generation_model'] = $this->is_text_generation_model_error($result);

        return $parsed;
    }

    /**
     * Whether the current builder configuration can still perform text generation.
     *
     * Uses the documented, cost-free `is_supported_for_text_generation()` check (no
     * network request). Assumes support when the check itself is unavailable, relying on
     * the post-hoc retry in generate() as the safety net.
     */
    private function builder_supports_text_generation(mixed $builder): bool
    {
        if (!is_object($builder) || !is_callable([$builder, 'is_supported_for_text_generation'])) {
            return true;
        }

        try {
            return (bool) $builder->is_supported_for_text_generation();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Log the raw WP_Error code/message for diagnosability, instead of only ever
     * surfacing the generic, parsed error text to the end user.
     */
    private function log_provider_error(string $connector_id, mixed $result, string $context): void
    {
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

    /**
     * Configure a prompt builder object.
     *
     * @param list<string> $ability_names Ability names exposed to the model.
     */
    private function configure_builder(
        mixed $builder,
        string $system_instruction,
        string $connector_id,
        array $ability_names,
    ): mixed {
        if (!is_object($builder)) {
            return $builder;
        }

        $configured_result = $builder->using_max_tokens(1000);
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
     * Register WordPress abilities as callable tools when supported.
     *
     * @param list<string> $ability_names Ability names exposed to the model.
     */
    private function apply_abilities(object $builder, array $ability_names): object
    {
        if ([] === $ability_names) {
            return $builder;
        }

        if (
            class_exists('WordPress\AiClient\Tools\DTO\FunctionDeclaration')
            && method_exists($builder, 'using_function_declarations')
        ) {
            $declarations = $this->build_function_declarations($ability_names);

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

    /**
     * Build provider-safe function declarations for AWPT abilities.
     *
     * @param list<string> $ability_names Ability names exposed to the model.
     * @return list<object>
     */
    private function build_function_declarations(array $ability_names): array
    {
        return new AbilityFunctionDeclarationBuilder()->build($ability_names);
    }

    /**
     * Generate a provider result object when available.
     */
    private function generate_result(mixed $builder): mixed
    {
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

    private function is_text_generation_model_error(mixed $result): bool
    {
        if (!is_wp_error($result)) {
            return false;
        }

        return (bool) preg_match(
            '/no models found.*text_generation|support text_generation/i',
            $result->get_error_message(),
        );
    }
}
