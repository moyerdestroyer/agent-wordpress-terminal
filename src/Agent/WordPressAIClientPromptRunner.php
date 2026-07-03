<?php

/**
 * Runs prompts through the optional WordPress AI Client.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\AbilitySchemas;

defined('ABSPATH') || exit();

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
        $configured = call_user_func([$builder, 'using_max_tokens'], 1000);

        if ('' !== $connector_id && is_object($configured) && method_exists($configured, 'using_provider')) {
            $configured = call_user_func([$configured, 'using_provider'], $connector_id);
        }

        $configured = $this->apply_model_preference($configured);
        $configured = $this->apply_abilities($configured, $ability_names);

        if ('' === $system_instruction) {
            return $configured;
        }

        return call_user_func([$configured, 'using_system_instruction'], $system_instruction);
    }

    /**
     * Register WordPress abilities as callable tools when supported.
     *
     * @param list<string> $ability_names Ability names exposed to the model.
     */
    private function apply_abilities(mixed $builder, array $ability_names): mixed
    {
        if ([] === $ability_names || !is_object($builder)) {
            return $builder;
        }

        if (
            class_exists('WordPress\AiClient\Tools\DTO\FunctionDeclaration')
            && method_exists($builder, 'using_function_declarations')
        ) {
            $declarations = $this->build_function_declarations($ability_names);

            if ([] !== $declarations) {
                return call_user_func_array([$builder, 'using_function_declarations'], $declarations);
            }
        }

        if (method_exists($builder, 'using_abilities')) {
            return call_user_func_array([$builder, 'using_abilities'], $ability_names);
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
            $input_schema = is_array($raw_schema) ? $raw_schema : AbilitySchemas::empty_object_input();
            $normalized_schema = AbilitySchemas::normalize_for_provider($input_schema);

            $declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
                $function_name,
                $ability->get_description(),
                $normalized_schema,
            );
        }

        return $declarations;
    }

    /**
     * Apply WordPress preferred model routing when available.
     */
    private function apply_model_preference(mixed $builder): mixed
    {
        if (
            !function_exists('WordPress\AI\get_preferred_models_for_text_generation')
            || !is_object($builder)
            || !method_exists($builder, 'using_model_preference')
        ) {
            return $builder;
        }

        $models = \WordPress\AI\get_preferred_models_for_text_generation();

        if ([] === $models) {
            return $builder;
        }

        return call_user_func_array([$builder, 'using_model_preference'], $models);
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
            return $builder->generate_text_result();
        }

        if (is_callable([$builder, 'generate_text'])) {
            return $builder->generate_text();
        }

        return '';
    }
}
