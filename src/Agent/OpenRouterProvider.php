<?php

/**
 * OpenRouter provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ArrayKey;

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
     * DeepSeek V4 Pro is the agent default: stronger at long-horizon tool loops and
     * large structured post_content (pattern-adapted landing pages). Override with
     * deepseek/deepseek-v4-flash or another OpenRouter ID when cost matters more.
     */
    private const DEFAULT_MODEL = 'deepseek/deepseek-v4-pro';

    /**
     * Get provider name.
     */
    public function get_name(): string {
        return 'OpenRouter';
    }

    public function accepts_image_input(): bool {
        return new OpenRouterModelCapabilities()->accepts_images($this->get_model());
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function decorate_request_payload(array $payload, array $options): array {
        if (is_array($payload['messages'] ?? null)) {
            $payload['messages'] = ProviderCacheAffinity::without_internal_boundary(ArrayKey::list_of_maps(
                $payload['messages'],
            ));
        }
        $applied = ProviderCacheAffinity::apply_openrouter($payload, [], ProviderCacheAffinity::key($options));

        return $applied['payload'];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     * @return array<string, string>
     */
    protected function decorate_request_headers(array $headers, array $options): array {
        return $headers;
    }
}
