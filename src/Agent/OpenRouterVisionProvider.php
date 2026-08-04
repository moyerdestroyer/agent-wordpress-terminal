<?php

/**
 * OpenRouter automatic vision sidecar.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Uses OpenRouter Auto only for bounded, tool-free image understanding.
 */
final class OpenRouterVisionProvider extends ChatCompletionsProvider {
    private const DEFAULT_MODEL = 'google/gemini-3-flash-preview';

    public function get_name(): string {
        return 'OpenRouter Vision';
    }

    public function accepts_image_input(): bool {
        return true;
    }

    protected function get_endpoint(): string {
        return 'https://openrouter.ai/api/v1/chat/completions';
    }

    protected function get_api_key(): string {
        return trim((string) get_option('awpt_openrouter_api_key', ''));
    }

    protected function get_missing_key_message(): string {
        return __(
            'OpenRouter image analysis is unavailable because its API key is not configured.',
            'agent-wordpress-terminal',
        );
    }

    protected function get_model(): string {
        /** @var string $model */
        $model = apply_filters('awpt_openrouter_vision_model', self::DEFAULT_MODEL);

        return trim($model);
    }

    protected function allows_text_only_image_fallback(): bool {
        return false;
    }

    /**
     * @param string $api_key OpenRouter API key.
     * @return array<string, string>
     */
    protected function get_headers(#[\SensitiveParameter] string $api_key): array {
        return array_merge(parent::get_headers($api_key), [
            'HTTP-Referer' => home_url('/'),
            'X-Title' => get_bloginfo('name') . ' — AWPT Vision',
        ]);
    }
}
