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
 * Model selection is automatic (OpenAI's evergreen `chat-latest` alias, which OpenAI
 * itself keeps pointed at its current recommended chat model) — there is no manual
 * model field to configure.
 */
final class OpenAIProvider extends ChatCompletionsProvider {
    /**
     * OpenAI's evergreen chat alias; always points at OpenAI's current recommended
     * chat-completions model, so AWPT never has to track specific model versions.
     */
    private const DEFAULT_MODEL = 'chat-latest';

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
     * Provider model identifier. Always automatic; not user-configurable.
     */
    protected function get_model(): string {
        /**
         * Filters the OpenAI model AWPT uses.
         *
         * @param string $model Model identifier. Defaults to OpenAI's evergreen
         *                       `chat-latest` alias.
         */
        return (string) apply_filters('awpt_openai_model', self::DEFAULT_MODEL);
    }
}
