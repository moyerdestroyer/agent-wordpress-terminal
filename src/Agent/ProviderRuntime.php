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
     * Error code WordPressAIClientProvider returns when the connector has no model
     * that can perform text generation for the current request, even after AWPT's
     * own retry-without-abilities safety net.
     */
    private const NO_TEXT_GENERATION_ERROR_CODE = 'awpt_connector_no_text_generation';

    /**
     * Get a provider-backed response.
     *
     * @param int $session_id Session ID.
     * @return array<string, mixed>
     */
    public function respond(int $session_id): array
    {
        $provider = new ProviderFactory()->make();
        $messages = new ProviderMessageBuilder()->build($session_id);
        $tool_registry = new ToolRegistry();
        $result = $provider->complete($messages, $tool_registry->get_chat_completion_tools());
        $notice = '';

        if (is_wp_error($result)) {
            $failover = $this->maybe_failover($provider, $result, $messages, $tool_registry);

            if (null !== $failover) {
                [$provider, $result, $notice] = $failover;
            }
        }

        if (is_wp_error($result)) {
            return [
                'content' => $result->get_error_message(),
                'tool_calls' => [],
                'actions' => [],
                'provider' => $provider->get_name(),
            ];
        }

        $result = new IntentToolCallEnricher()->enrich($messages, $result, $tool_registry);
        $response = $this->finalize_response($session_id, $provider, $messages, $result, $tool_registry);

        if ('' !== $notice) {
            $response['content'] = trim($notice . "\n\n" . (string) $response['content']);
        }

        return $response;
    }

    /**
     * Fail over to the guaranteed OpenRouter baseline when the WordPress AI Client
     * connector cannot perform text generation at all, so a Connectors misconfiguration
     * never blocks basic agent use.
     *
     * @param array<int, array<string, mixed>> $messages Provider messages.
     * @return array{0: ProviderInterface, 1: array<string, mixed>|\WP_Error, 2: string}|null
     */
    private function maybe_failover(
        ProviderInterface $provider,
        \WP_Error $error,
        array $messages,
        ToolRegistry $tool_registry,
    ): ?array {
        if (
            !$provider instanceof WordPressAIClientProvider
            || self::NO_TEXT_GENERATION_ERROR_CODE !== $error->get_error_code()
        ) {
            return null;
        }

        $fallback = new OpenRouterProvider();
        $fallback_result = $fallback->complete($messages, $tool_registry->get_chat_completion_tools());

        $notice = sprintf(
            /* translators: %s: original connector/provider name. */
            __(
                '[AWPT] "%s" has no model available for text generation, so this reply used OpenRouter instead. Check AI connection settings.',
                'agent-wordpress-terminal',
            ),
            $provider->get_name(),
        );

        return [$fallback, $fallback_result, $notice];
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
        $loop_result = new ProviderToolLoop()->run($session_id, $provider, $messages, $result, $tool_registry);

        return [
            'content' => $loop_result['content'],
            'tool_calls' => $loop_result['tool_calls'],
            'actions' => $loop_result['actions'],
            'provider' => $provider->get_name(),
            'model' => $loop_result['model'],
        ];
    }
}
