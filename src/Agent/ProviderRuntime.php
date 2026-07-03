<?php

/**
 * Provider-backed agent loop.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs natural language messages through the configured provider.
 */
final class ProviderRuntime
{
    /**
     * Get a provider-backed response.
     *
     * @param int $session_id Session ID.
     * @return array<string, mixed>
     */
    public function respond(int $session_id): array
    {
        $provider = (new ProviderFactory())->make();
        $messages = (new ProviderMessageBuilder())->build($session_id);
        $tool_registry = new ToolRegistry();
        $result = $provider->complete($messages, $tool_registry->get_chat_completion_tools());

        if (is_wp_error($result)) {
            return [
                'content' => $result->get_error_message(),
                'tool_calls' => [],
                'actions' => [],
                'provider' => $provider->get_name(),
            ];
        }

        $result = (new IntentToolCallEnricher())->enrich($messages, $result, $tool_registry);

        return $this->finalize_response($session_id, $provider, $messages, $result, $tool_registry);
    }

    /**
     * Finalize provider response, including bounded tool rounds.
     *
     * @param int                               $session_id Session ID.
     * @param ProviderInterface                 $provider Provider.
     * @param array<int, array<string, mixed>>  $messages Provider messages.
     * @param array<string, mixed>              $result Provider result.
     * @param ToolRegistry                      $tool_registry Tool registry.
     * @return array<string, mixed>
     */
    private function finalize_response(
        int $session_id,
        ProviderInterface $provider,
        array $messages,
        array $result,
        ToolRegistry $tool_registry,
    ): array {
        $loop_result = (new ProviderToolLoop())->run($session_id, $provider, $messages, $result, $tool_registry);

        return [
            'content' => $loop_result['content'],
            'tool_calls' => $loop_result['tool_calls'],
            'actions' => $loop_result['actions'],
            'provider' => $provider->get_name(),
            'model' => $loop_result['model'],
        ];
    }
}
