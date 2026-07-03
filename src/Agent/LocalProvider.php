<?php

/**
 * Local OpenAI-compatible provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Local OpenAI-compatible chat completions provider.
 */
final class LocalProvider extends ChatCompletionsProvider
{
    /**
     * Get provider name.
     */
    public function get_name(): string
    {
        return 'local';
    }

    /**
     * Provider endpoint.
     */
    protected function get_endpoint(): string
    {
        return trim((string) get_option('awpt_local_endpoint', ''));
    }

    /**
     * Provider API key.
     */
    protected function get_api_key(): string
    {
        return trim((string) get_option('awpt_local_api_key', ''));
    }

    /**
     * Missing key message.
     */
    protected function get_missing_key_message(): string
    {
        return __('Fallback local provider API key is not configured.', 'agent-wordpress-terminal');
    }

    /**
     * Missing endpoint message.
     */
    protected function get_missing_endpoint_message(): string
    {
        return __(
            'Fallback local provider endpoint is not configured. Add an OpenAI-compatible chat completions URL in AWPT AI connection settings.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Local providers often do not require authentication.
     */
    protected function requires_api_key(): bool
    {
        return false;
    }

    /**
     * Request headers.
     *
     * @param string $api_key API key.
     * @return array<string, string>
     */
    protected function get_headers(#[\SensitiveParameter] string $api_key): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ('' !== $api_key) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        return $headers;
    }
}
