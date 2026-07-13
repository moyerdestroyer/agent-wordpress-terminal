<?php

/**
 * WordPress AI Client provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\AbilitySchemas;
use AWPT\Support\ConnectorCatalog;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Uses WordPress Core's AI Client and Connectors infrastructure when available.
 *
 * Optional path — baseline chat remains ChatCompletionsProvider + OpenAI/OpenRouter.
 */
final class WordPressAIClientProvider implements ProviderInterface {
    private string $connector_id;

    public function __construct(string $connector_id) {
        $this->connector_id = sanitize_key($connector_id);
    }

    /**
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     * @param array<int, array<string, mixed>> $tools Available tools.
     * @return array<string, mixed>|\WP_Error
     */
    public function complete(array $messages, array $tools = []): array|\WP_Error {
        unset($tools);

        if (!function_exists('wp_ai_client_prompt')) {
            return $this->response(
                'WordPress AI Client is not available. Select OpenRouter or install a WordPress AI connector plugin.',
            );
        }

        $catalog = new ConnectorCatalog();

        if (!$catalog->is_valid_provider($this->connector_id)) {
            return $this->response(__(
                'The selected AI connector is not available. Choose another connector in AWPT settings.',
                'agent-wordpress-terminal',
            ));
        }

        foreach ($catalog->list_installed_connectors() as $connector) {
            if ($connector['id'] !== $this->connector_id) {
                continue;
            }

            if (!$connector['ready']) {
                return $this->response(sprintf(
                    /* translators: 1: connector name, 2: connector status */
                    __(
                        'The %1$s connector is not ready (%2$s). Configure it under Settings > Connectors.',
                        'agent-wordpress-terminal',
                    ),
                    $connector['name'],
                    $connector['status_label'],
                ));
            }

            break;
        }

        $result = new WordPressAIClientRunner()->generate(
            $messages,
            $this->connector_id,
            new ToolRegistry()->get_auto_executable_ability_names(),
        );

        if ($result['no_text_generation_model']) {
            return new \WP_Error(
                'awpt_connector_no_text_generation',
                '' !== $result['content']
                    ? $result['content']
                    : __(
                        'The selected AI connector has no model available that supports text generation.',
                        'agent-wordpress-terminal',
                    ),
            );
        }

        return [
            'content' => $result['content'],
            'raw_tool_calls' => $result['raw_tool_calls'],
            'message' => [
                'role' => 'assistant',
                'content' => $result['content'],
                'tool_calls' => $result['raw_tool_calls'],
            ],
            'model' => $result['model'],
            'usage' => [],
        ];
    }

    public function get_name(): string {
        return new ConnectorCatalog()->get_provider_label($this->connector_id);
    }

    /**
     * Shared helper for settings UI preflight.
     *
     * @param list<string> $ability_names
     * @return list<object>
     */
    public static function build_function_declarations(array $ability_names): array {
        if (!function_exists('wp_get_ability') || !class_exists('WP_AI_Client_Ability_Function_Resolver')) {
            return [];
        }

        $declarations = [];

        foreach ($ability_names as $ability_name) {
            $ability = wp_get_ability($ability_name);

            if (null === $ability) {
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
     * @return array<string, mixed>
     */
    private function response(string $content): array {
        return [
            'content' => $content,
            'raw_tool_calls' => [],
            'message' => [
                'role' => 'assistant',
                'content' => $content,
            ],
            'model' => '',
            'usage' => [],
        ];
    }
}
