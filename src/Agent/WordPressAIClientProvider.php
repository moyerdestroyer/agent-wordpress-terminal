<?php

/**
 * WordPress AI Client provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ConnectorCatalog;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Uses WordPress Core's AI Client and Connectors infrastructure when available.
 */
final class WordPressAIClientProvider implements ProviderInterface
{
    /**
     * Selected connector ID.
     */
    private string $connector_id;

    /**
     * @param string $connector_id Connector registry ID.
     */
    public function __construct(string $connector_id)
    {
        $this->connector_id = sanitize_key($connector_id);
    }

    /**
     * Complete a chat request through wp_ai_client_prompt().
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     * @param array<int, array<string, mixed>> $tools Available tools.
     * @return array<string, mixed>
     */
    public function complete(array $messages, array $tools = []): array
    {
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

        $tool_registry = new ToolRegistry();
        $result = new WordPressAIClientPromptRunner()->generate(
            $messages,
            $this->connector_id,
            $tool_registry->get_auto_executable_ability_names(),
        );

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

    /**
     * Provider identifier.
     */
    public function get_name(): string
    {
        return new ConnectorCatalog()->get_provider_label($this->connector_id);
    }

    /**
     * Build a normalized provider response.
     *
     * @return array<string, mixed>
     */
    private function response(string $content): array
    {
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
