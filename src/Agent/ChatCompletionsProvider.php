<?php

/**
 * Chat completions provider base.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shared HTTP implementation for OpenAI-compatible chat providers.
 */
abstract class ChatCompletionsProvider implements ProviderInterface {
    /**
     * Complete a chat request.
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     * @param array<int, array<string, mixed>> $tools Available tools.
     * @return array<string, mixed>|\WP_Error
     */
    public function complete(array $messages, array $tools = []): array|\WP_Error {
        $api_key = $this->get_api_key();

        if ($this->requires_api_key() && '' === $api_key) {
            return new \WP_Error('awpt_provider_not_configured', $this->get_missing_key_message());
        }

        $endpoint = $this->get_endpoint();

        if ('' === $endpoint) {
            return new \WP_Error('awpt_provider_not_configured', $this->get_missing_endpoint_message());
        }

        $model = $this->get_model();

        if ('' === $model) {
            return new \WP_Error('awpt_model_not_configured', __(
                'Fallback model is not configured. Add a model in AWPT AI connection settings.',
                'agent-wordpress-terminal',
            ));
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => 1000,
        ];

        if ([] !== $tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 45,
            'headers' => $this->get_headers($api_key),
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            return new \WP_Error(
                'awpt_provider_request_failed',
                $this->format_error_message($status, is_array($data) ? $data : [], $body),
                ['status' => $status],
            );
        }

        if (!is_array($data)) {
            return new \WP_Error('awpt_provider_invalid_response', __(
                'Provider returned an invalid JSON response.',
                'agent-wordpress-terminal',
            ));
        }

        $message = $data['choices'][0]['message'] ?? null;

        if (!is_array($message)) {
            return new \WP_Error('awpt_provider_invalid_response', __(
                'Provider response did not include an assistant message.',
                'agent-wordpress-terminal',
            ));
        }

        return [
            'content' => $this->stringify_content($message['content'] ?? ''),
            'raw_tool_calls' => is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [],
            'message' => $message,
            'model' => (string) ($data['model'] ?? $model),
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        ];
    }

    /**
     * Provider endpoint.
     */
    abstract protected function get_endpoint(): string;

    /**
     * Provider API key.
     */
    abstract protected function get_api_key(): string;

    /**
     * Missing key message.
     */
    abstract protected function get_missing_key_message(): string;

    /**
     * Missing endpoint message.
     */
    protected function get_missing_endpoint_message(): string {
        return __(
            'Fallback provider endpoint is not configured. Add it in AWPT AI connection settings.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Whether this provider requires an API key.
     */
    protected function requires_api_key(): bool {
        return true;
    }

    /**
     * Provider model identifier.
     */
    protected function get_model(): string {
        return trim((string) get_option('awpt_model', ''));
    }

    /**
     * Request headers.
     *
     * @param string $api_key API key.
     * @return array<string, string>
     */
    protected function get_headers(#[\SensitiveParameter] string $api_key): array {
        return [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Format provider error messages without exposing request secrets.
     *
     * @param int                  $status HTTP status.
     * @param array<string, mixed> $data Response data.
     * @param string               $body Raw response body.
     */
    private function format_error_message(int $status, array $data, string $body): string {
        $message = $data['error']['message'] ?? $data['message'] ?? '';

        if (is_string($message) && '' !== $message) {
            return sprintf(
                /* translators: 1: HTTP status code, 2: provider error message */
                __('Provider request failed (%1$d): %2$s', 'agent-wordpress-terminal'),
                $status,
                $message,
            );
        }

        return sprintf(
            /* translators: 1: HTTP status code, 2: provider response */
            __('Provider request failed (%1$d): %2$s', 'agent-wordpress-terminal'),
            $status,
            wp_trim_words($body, 40),
        );
    }

    /**
     * Normalize assistant content.
     *
     * @param mixed $content Raw content.
     */
    private function stringify_content(mixed $content): string {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $part) {
            if (!(is_array($part) && array_key_exists('text', $part) && is_string($part['text']))) {
                continue;
            }

            $parts[] = $part['text'];
        }

        return implode("\n", $parts);
    }
}
