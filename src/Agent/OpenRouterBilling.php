<?php

/**
 * OpenRouter account usage / billing lookup.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ArrayKey;
use AWPT\Support\ConnectorSelection;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fetches OpenRouter key usage and optional account credit balance.
 *
 * Primary source is GET /api/v1/key (works with a normal API key). Account
 * balance via GET /api/v1/credits is included when the key is allowed to read it
 * (management keys); regular keys typically omit that field.
 */
final class OpenRouterBilling {
    private const KEY_ENDPOINT = 'https://openrouter.ai/api/v1/key';

    private const CREDITS_ENDPOINT = 'https://openrouter.ai/api/v1/credits';

    /** OpenRouter documents credit values as up to ~60s stale; match that. */
    private const CACHE_SECONDS = 60;

    private const CACHE_KEY = 'awpt_openrouter_billing_v1';

    /**
     * Cached summary for the terminal UI when OpenRouter is active.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function get_summary(): array|\WP_Error {
        return $this->resolve_summary(false);
    }

    /**
     * Bypass the short-lived cache and re-fetch from OpenRouter.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function refresh_summary(): array|\WP_Error {
        return $this->resolve_summary(true);
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function resolve_summary(bool $force_refresh): array|\WP_Error {
        $selection = new ConnectorSelection();
        $provider_id = $selection->normalize_provider_option((string) get_option('awpt_provider', ''));

        if ('openrouter' !== $provider_id) {
            return [
                'available' => false,
                'reason' => 'not_openrouter',
            ];
        }

        $api_key = trim((string) get_option('awpt_openrouter_api_key', ''));

        if ('' === $api_key) {
            return [
                'available' => false,
                'reason' => 'not_configured',
            ];
        }

        if (!$force_refresh) {
            $cached = get_transient(self::CACHE_KEY);

            if (is_array($cached) && true === ($cached['available'] ?? null)) {
                return ArrayKey::as_map($cached);
            }
        }

        $key_payload = $this->fetch_json(self::KEY_ENDPOINT, $api_key);

        if (is_wp_error($key_payload)) {
            return $key_payload;
        }

        $key_data = ArrayKey::as_map($key_payload['data'] ?? $key_payload);

        $summary = [
            'available' => true,
            'provider' => 'openrouter',
            'label' => $this->nullable_string($key_data['label'] ?? null),
            'usage' => $this->as_float($key_data['usage'] ?? 0),
            'usage_daily' => $this->as_float($key_data['usage_daily'] ?? 0),
            'usage_weekly' => $this->as_float($key_data['usage_weekly'] ?? 0),
            'usage_monthly' => $this->as_float($key_data['usage_monthly'] ?? 0),
            'limit' => $this->nullable_float($key_data['limit'] ?? null),
            'limit_remaining' => $this->nullable_float($key_data['limit_remaining'] ?? null),
            'limit_reset' => $this->nullable_string($key_data['limit_reset'] ?? null),
            'is_free_tier' => $this->as_bool($key_data['is_free_tier'] ?? false),
            'balance' => null,
            'total_credits' => null,
            'total_usage' => null,
            'fetched_at' => gmdate('c'),
        ];

        $credits_payload = $this->fetch_json(self::CREDITS_ENDPOINT, $api_key);

        if (!is_wp_error($credits_payload)) {
            $credits_data = ArrayKey::as_map($credits_payload['data'] ?? $credits_payload);
            $total_credits = $this->nullable_float($credits_data['total_credits'] ?? null);
            $total_usage = $this->nullable_float($credits_data['total_usage'] ?? null);
            $summary['total_credits'] = $total_credits;
            $summary['total_usage'] = $total_usage;

            if (null !== $total_credits && null !== $total_usage) {
                $summary['balance'] = round($total_credits - $total_usage, 6);
            }
        }

        set_transient(self::CACHE_KEY, $summary, self::CACHE_SECONDS);

        return $summary;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function fetch_json(string $url, #[\SensitiveParameter] string $api_key): array|\WP_Error {
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer' => home_url('/'),
                'X-Title' => get_bloginfo('name'),
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('awpt_openrouter_billing_request_failed', sprintf(
                /* translators: %s: transport error message */
                __('Could not reach OpenRouter billing: %s', 'agent-wordpress-terminal'),
                $response->get_error_message(),
            ));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? '') : '';

            if ('' === $message) {
                $message = sprintf(
                    /* translators: %d: HTTP status code */
                    __('OpenRouter billing request failed (HTTP %d).', 'agent-wordpress-terminal'),
                    $status,
                );
            }

            return new \WP_Error('awpt_openrouter_billing_request_failed', $message, ['status' => $status]);
        }

        return ArrayKey::as_map($decoded);
    }

    private function as_float(mixed $value): float {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function as_bool(mixed $value): bool {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
    }

    private function nullable_float(mixed $value): ?float {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function nullable_string(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
