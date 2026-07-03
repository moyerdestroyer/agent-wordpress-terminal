<?php

/**
 * Runs prompts through the optional WordPress AI Client.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\AbilitySchemas;

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
     * @return array{content: string, raw_tool_calls: array<int, array<string, mixed>>, model: string}
     */
    public function generate(array $messages, string $connector_id, array $ability_names = []): array
    {
        $system_instruction = $this->formatter->extract_system_instruction($messages);
        $prompt = $this->formatter->build_prompt_text($messages);
        $builder = call_user_func('wp_ai_client_prompt', $prompt);
        $builder = $this->configure_builder($builder, $system_instruction, $connector_id, $ability_names);
        $result = $this->generate_result($builder);

        if ([] !== $ability_names && $this->is_text_generation_model_error($result)) {
            $builder = call_user_func('wp_ai_client_prompt', $prompt);
            $builder = $this->configure_builder($builder, $system_instruction, $connector_id, []);
            $result = $this->generate_result($builder);
        }

        return $this->parser->parse($result);
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
        if (!function_exists('wp_get_ability')) {
            return [];
        }

        $declarations = [];

        foreach ($ability_names as $ability_name) {
            $ability = wp_get_ability($ability_name);

            if (null === $ability || !class_exists('WP_AI_Client_Ability_Function_Resolver')) {
                continue;
            }

            $function_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name($ability_name);
            $raw_schema = method_exists($ability, 'get_input_schema')
                ? $ability->get_input_schema()
                : AbilitySchemas::empty_object_input();
            $normalized_schema = AbilitySchemas::normalize_for_provider($raw_schema);

            $declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
                $function_name,
                $ability->get_description(),
                $normalized_schema,
            );
        }

        return $declarations;
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
