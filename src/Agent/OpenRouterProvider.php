<?php

/**
 * OpenRouter provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * OpenRouter API provider.
 */
final class OpenRouterProvider extends ChatCompletionsProvider {
    /**
     * Default OpenRouter model when no explicit model is configured.
     *
     * DeepSeek V4 Flash is the balanced agent default: tool-capable, long context,
     * and cheap enough for multi-step ability loops. Override with Pro or another
     * OpenRouter ID when a site needs more headroom.
     */
    private const DEFAULT_MODEL = 'deepseek/deepseek-v4-flash';

    /**
     * Get provider name.
     */
    public function get_name(): string {
        return 'OpenRouter';
    }

    /**
     * Provider endpoint.
     */
    protected function get_endpoint(): string {
        return 'https://openrouter.ai/api/v1/chat/completions';
    }

    /**
     * Provider API key.
     */
    protected function get_api_key(): string {
        return trim((string) get_option('awpt_openrouter_api_key', ''));
    }

    /**
     * Missing key message.
     */
    protected function get_missing_key_message(): string {
        return __(
            'OpenRouter API key is not configured. Add it in AWPT AI connection settings.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Provider model identifier.
     */
    protected function get_model(): string {
        $model = trim((string) get_option('awpt_openrouter_model', self::DEFAULT_MODEL));

        if ('' === $model || in_array($model, ['openrouter/auto', 'openrouter/auto-beta'], true)) {
            return self::DEFAULT_MODEL;
        }

        return $model;
    }

    /**
     * Request headers.
     *
     * @param string $api_key API key.
     * @return array<string, string>
     */
    protected function get_headers(#[\SensitiveParameter] string $api_key): array {
        return array_merge(parent::get_headers($api_key), [
            'HTTP-Referer' => home_url('/'),
            'X-Title' => get_bloginfo('name'),
        ]);
    }
}
