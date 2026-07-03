<?php

/**
 * OpenAI provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

defined('ABSPATH') || exit();

/**
 * OpenAI API provider.
 */
final class OpenAIProvider extends ChatCompletionsProvider
{
    /**
     * Get provider name.
     */
    public function get_name(): string
    {
        return 'openai';
    }

    /**
     * Provider endpoint.
     */
    protected function get_endpoint(): string
    {
        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Provider API key.
     */
    protected function get_api_key(): string
    {
        return trim((string) get_option('awpt_openai_api_key', ''));
    }

    /**
     * Missing key message.
     */
    protected function get_missing_key_message(): string
    {
        return __(
            'Fallback OpenAI API key is not configured. Add it in AWPT AI connection settings.',
            'agent-wordpress-terminal',
        );
    }
}
