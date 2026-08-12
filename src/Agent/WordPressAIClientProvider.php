<?php

/**
 * WordPress AI Client provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\AiLogger;
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
    public function complete(array $messages, array $tools = [], array $options = []): array|\WP_Error {
        $started_at = microtime(true);

        if (!function_exists('wp_ai_client_prompt')) {
            $error = $this->response(
                'WordPress AI Client is not available. Select OpenRouter or install a WordPress AI connector plugin.',
            );
            $this->log_complete($messages, $tools, $options, $error, ['started_at' => $started_at]);

            return $error;
        }

        $catalog = new ConnectorCatalog();

        if (!$catalog->is_valid_provider($this->connector_id)) {
            $error = $this->response(__(
                'The selected AI connector is not available. Choose another connector in AWPT settings.',
                'agent-wordpress-terminal',
            ));
            $this->log_complete($messages, $tools, $options, $error, ['started_at' => $started_at]);

            return $error;
        }

        foreach ($catalog->list_installed_connectors() as $connector) {
            if ($connector['id'] !== $this->connector_id) {
                continue;
            }

            if (!$connector['ready']) {
                $error = $this->response(sprintf(
                    /* translators: 1: connector name, 2: connector status */
                    __(
                        'The %1$s connector is not ready (%2$s). Configure it under Settings > Connectors.',
                        'agent-wordpress-terminal',
                    ),
                    $connector['name'],
                    $connector['status_label'],
                ));
                $this->log_complete($messages, $tools, $options, $error, ['started_at' => $started_at]);

                return $error;
            }

            break;
        }

        $ability_names = [];
        $registry = new ToolRegistry();

        foreach ($tools as $tool) {
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : [];
            $ability_name = $registry->tool_name_for_function((string) ($function['name'] ?? ''));

            if (null !== $ability_name && $registry->is_ability($ability_name)) {
                $ability_names[] = $ability_name;
            }
        }

        $result = new WordPressAIClientRunner()->generate(
            $messages,
            $this->connector_id,
            array_values(array_unique($ability_names)),
            max(1024, min(32_000, (int) ($options['max_completion_tokens'] ?? 8192))),
        );

        if ($result['no_text_generation_model']) {
            $error = new \WP_Error(
                'awpt_connector_no_text_generation',
                '' !== $result['content']
                    ? $result['content']
                    : __(
                        'The selected AI connector has no model available that supports text generation.',
                        'agent-wordpress-terminal',
                    ),
            );
            $this->log_complete($messages, $tools, $options, $error, [
                'started_at' => $started_at,
                'meta' => [
                    'connector_id' => $this->connector_id,
                    'ability_names' => $ability_names,
                ],
            ]);

            return $error;
        }

        $success = [
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
        $this->log_complete($messages, $tools, $options, $success, [
            'started_at' => $started_at,
            'meta' => [
                'connector_id' => $this->connector_id,
                'ability_names' => $ability_names,
            ],
        ]);

        return $success;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>             $options
     * @param array<string, mixed>|\WP_Error   $result
     * @param array{started_at?: float, meta?: array<string, mixed>} $context
     */
    private function log_complete(
        array $messages,
        array $tools,
        array $options,
        array|\WP_Error $result,
        array $context = [],
    ): void {
        AiLogger::log_provider_complete([
            'provider' => $this->get_name(),
            'messages' => $messages,
            'tools' => $tools,
            'options' => $options,
            'result' => $result,
            'started_at' => is_float($context['started_at'] ?? null) ? $context['started_at'] : microtime(true),
            'meta' => is_array($context['meta'] ?? null) ? $context['meta'] : [],
        ]);
    }

    public function get_name(): string {
        return new ConnectorCatalog()->get_provider_label($this->connector_id);
    }

    /**
     * The current adapter flattens multipart messages into prompt text.
     */
    public function accepts_image_input(): bool {
        return false;
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
            $raw_schema = method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : [];
            $schema = $raw_schema;
            $normalized_schema = new AbilityTransportCodec()->provider_schema($schema);

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
