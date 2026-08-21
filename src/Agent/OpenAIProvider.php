<?php

/**
 * OpenAI provider adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ConnectorInspector;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * OpenAI API provider.
 *
 * Default model is configurable (`awpt_openai_model`); falls back to
 * {@see OpenAIProvider::DEFAULT_MODEL} when unset.
 */
final class OpenAIProvider extends ChatCompletionsProvider {
    /**
     * Default Chat Completions model (Improve matrix: more consistent than
     * DeepSeek Flash / Terra on docs pattern-replace).
     */
    public const DEFAULT_MODEL = 'gpt-5.6-luna';

    /**
     * Get provider name.
     */
    public function get_name(): string {
        return 'OpenAI';
    }

    /**
     * Provider endpoint.
     */
    protected function get_endpoint(): string {
        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Provider API key.
     *
     * Prefers a key explicitly entered in AWPT settings; otherwise reuses whatever key
     * is already configured for the `openai` WordPress Connector (env var, PHP
     * constant, or database option), so the user never has to enter the same key
     * twice.
     */
    protected function get_api_key(): string {
        $own_key = trim((string) get_option('awpt_openai_api_key', ''));

        if ('' !== $own_key) {
            return $own_key;
        }

        return new ConnectorInspector()->resolve_default_provider_api_key('openai');
    }

    /**
     * Missing key message.
     */
    protected function get_missing_key_message(): string {
        return __(
            'No OpenAI API key found. Add one in AWPT AI connection settings, or configure the OpenAI connector under Settings > Connectors.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Provider model identifier.
     */
    protected function get_model(): string {
        $configured = trim((string) get_option('awpt_openai_model', ''));
        $default = '' !== $configured ? $configured : self::DEFAULT_MODEL;

        /**
         * Filters the OpenAI model AWPT uses.
         *
         * @param string $model Model identifier (e.g. gpt-5.6-luna, gpt-5.6-terra).
         */
        return (string) apply_filters('awpt_openai_model', $default);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function decorate_request_payload(array $payload, array $options): array {
        $payload = ProviderCacheAffinity::apply_openai($payload, $options);

        // GPT-5.6-* rejects function tools on Chat Completions unless reasoning is
        // explicitly disabled (default effort is medium even when unset).
        if (
            [] !== ($payload['tools'] ?? [])
            && self::model_requires_tools_without_reasoning((string) ($payload['model'] ?? ''))
        ) {
            $payload['reasoning_effort'] = 'none';
        }

        return $payload;
    }

    private static function model_requires_tools_without_reasoning(string $model): bool {
        $model = strtolower(trim($model));

        return str_starts_with($model, 'gpt-5.6') || str_contains($model, '/gpt-5.6');
    }
}
