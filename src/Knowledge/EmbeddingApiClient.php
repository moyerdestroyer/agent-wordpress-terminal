<?php

/**
 * HTTP client for OpenAI-compatible embeddings endpoints.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Support\ConnectorInspector;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves keys and calls embeddings APIs.
 */
final class EmbeddingApiClient {
    public const PROVIDER_OPENROUTER = 'openrouter';

    public const PROVIDER_OPENAI = 'openai';

    public function resolve_api_key(): string {
        $openrouter = trim((string) get_option('awpt_openrouter_api_key', ''));

        if ('' !== $openrouter) {
            return $openrouter;
        }

        $openai = trim((string) get_option('awpt_openai_api_key', ''));

        if ('' !== $openai) {
            return $openai;
        }

        return new ConnectorInspector()->resolve_default_provider_api_key('openai');
    }

    public function provider_label(): string {
        if ('' !== trim((string) get_option('awpt_openrouter_api_key', ''))) {
            return 'openrouter';
        }

        if ('' !== trim((string) get_option('awpt_openai_api_key', ''))) {
            return 'openai';
        }

        $resolved = new ConnectorInspector()->resolve_default_provider_api_key('openai');

        return '' !== $resolved ? 'openai' : '';
    }

    public function model_for_request(string $model): string {
        $model = trim($model);

        if ('' === $model) {
            $model = EmbeddingService::DEFAULT_MODEL;
        }

        if (self::PROVIDER_OPENAI === $this->provider_label() && str_starts_with($model, 'openai/')) {
            return substr($model, strlen('openai/'));
        }

        if (
            self::PROVIDER_OPENROUTER === $this->provider_label()
            && in_array($model, ['text-embedding-3-small', 'text-embedding-3-large'], true)
        ) {
            return 'openai/' . $model;
        }

        return $model;
    }

    public function endpoint(): string {
        if ('' !== trim((string) get_option('awpt_openrouter_api_key', ''))) {
            return 'https://openrouter.ai/api/v1/embeddings';
        }

        return 'https://api.openai.com/v1/embeddings';
    }

    /**
     * @param list<string> $texts
     * @return list<?list<float>>
     */
    public function request(string $model, array $texts): array {
        $count = count($texts);
        $api_key = $this->resolve_api_key();
        $endpoint = $this->endpoint();

        if ('' === $api_key || '' === $endpoint || $count <= 0) {
            if ($count > 0) {
                $this->record_error(__('No embeddings API key is configured.', 'agent-wordpress-terminal'));
            }

            return $this->null_vectors($count);
        }

        $model = $this->model_for_request($model);

        $body = wp_json_encode([
            'model' => $model,
            'input' => array_values($texts),
        ]);

        if (!is_string($body) || '' === $body) {
            $this->record_error(__('The embeddings request could not be encoded.', 'agent-wordpress-terminal'));

            return $this->null_vectors($count);
        }

        $response = wp_remote_post($endpoint, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url('/'),
                'X-Title' => 'AWPT Knowledge',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->record_error(sprintf(
                /* translators: %s: HTTP transport error */
                __('Embeddings request failed: %s', 'agent-wordpress-terminal'),
                $response->get_error_message(),
            ));

            return $this->null_vectors($count);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            $message = is_array($decoded) && is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? '')
                : '';
            $detail = '' !== trim($message)
                ? trim($message)
                : __('Unexpected provider response.', 'agent-wordpress-terminal');
            $this->record_error(sprintf(
                /* translators: 1: HTTP status code, 2: provider error */
                __('Embeddings API returned HTTP %1$d: %2$s', 'agent-wordpress-terminal'),
                $status,
                $detail,
            ));

            return $this->null_vectors($count);
        }

        /** @var array<array-key, mixed> $data */
        $data = $decoded['data'];

        return $this->parse_data($data, $count);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<?list<float>>
     */
    private function parse_data(array $data, int $count): array {
        $out = $this->null_vectors($count);

        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            $index = (int) ($row['index'] ?? -1);
            $embedding = $row['embedding'] ?? null;

            if ($index < 0 || $index >= $count || !is_array($embedding)) {
                continue;
            }

            $floats = [];

            foreach ($embedding as $value) {
                if (!is_numeric($value)) {
                    continue;
                }

                $floats[] = (float) $value;
            }

            $out[$index] = [] === $floats ? null : $floats;
        }

        return $out;
    }

    /**
     * @return list<?list<float>>
     */
    private function null_vectors(int $count): array {
        if ($count <= 0) {
            return [];
        }

        return array_fill(0, $count, null);
    }

    private function record_error(string $message): void {
        $message = sanitize_text_field($message);
        update_option(EmbeddingService::OPTION_LAST_ERROR, mb_substr($message, 0, 500, 'UTF-8'), false);
    }
}
