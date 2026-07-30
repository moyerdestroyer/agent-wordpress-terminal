<?php

/**
 * Tests for OpenRouter billing/usage lookup.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\OpenRouterBilling;

function test_openrouter_billing_unavailable_when_provider_is_not_openrouter(): void {
    awpt_test_reset_state();
    update_option('awpt_provider', 'openai');
    update_option('awpt_openrouter_api_key', 'sk-or-test');

    $summary = new OpenRouterBilling()->get_summary();

    Assert::true(is_array($summary), 'summary should be an array when provider is not OpenRouter');
    Assert::false($summary['available'] ?? true, 'billing should be unavailable for non-OpenRouter providers');
    Assert::same('not_openrouter', $summary['reason'] ?? null, 'reason should explain the inactive provider');
    Assert::same(0, count($GLOBALS['awpt_test_http_requests']), 'no OpenRouter HTTP calls should be made');
}

test_openrouter_billing_unavailable_when_provider_is_not_openrouter();

function test_openrouter_billing_unavailable_without_api_key(): void {
    awpt_test_reset_state();
    update_option('awpt_provider', 'openrouter');
    update_option('awpt_openrouter_api_key', '');

    $summary = new OpenRouterBilling()->get_summary();

    Assert::true(is_array($summary), 'summary should be an array when the key is missing');
    Assert::false($summary['available'] ?? true, 'billing should be unavailable without an API key');
    Assert::same('not_configured', $summary['reason'] ?? null, 'reason should explain the missing key');
}

test_openrouter_billing_unavailable_without_api_key();

function test_openrouter_billing_fetches_key_usage_and_optional_balance(): void {
    awpt_test_reset_state();
    update_option('awpt_provider', 'openrouter');
    update_option('awpt_openrouter_api_key', 'sk-or-test');

    $key_hits = 0;
    $credits_hits = 0;
    $GLOBALS['awpt_test_http_get_response'] = static function (string $url) use (&$key_hits, &$credits_hits): array {
        if (str_contains($url, '/api/v1/key')) {
            ++$key_hits;

            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'data' => [
                        'label' => 'AWPT key',
                        'usage' => 12.5,
                        'usage_daily' => 0.42,
                        'usage_weekly' => 1.1,
                        'usage_monthly' => 3.75,
                        'limit' => 10.0,
                        'limit_remaining' => 6.25,
                        'limit_reset' => 'monthly',
                        'is_free_tier' => false,
                    ],
                ]),
            ];
        }

        if (str_contains($url, '/api/v1/credits')) {
            ++$credits_hits;

            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'data' => [
                        'total_credits' => 100.0,
                        'total_usage' => 37.5,
                    ],
                ]),
            ];
        }

        return [
            'response' => ['code' => 404],
            'body' => wp_json_encode(['error' => ['message' => 'not found']]),
        ];
    };

    $summary = new OpenRouterBilling()->get_summary();

    Assert::true(is_array($summary), 'successful billing lookup should return an array');
    Assert::true($summary['available'] ?? false, 'billing should be available for a configured OpenRouter key');
    Assert::same('openrouter', $summary['provider'] ?? null, 'provider should be openrouter');
    Assert::same('AWPT key', $summary['label'] ?? null, 'key label should be surfaced');
    Assert::same(0.42, $summary['usage_daily'] ?? null, 'daily usage should map from the key endpoint');
    Assert::same(3.75, $summary['usage_monthly'] ?? null, 'monthly usage should map from the key endpoint');
    Assert::same(6.25, $summary['limit_remaining'] ?? null, 'remaining key limit should be exposed');
    Assert::same(62.5, $summary['balance'] ?? null, 'balance should be total_credits - total_usage');
    Assert::true($key_hits >= 1, 'key endpoint should be requested');
    Assert::true($credits_hits >= 1, 'credits endpoint should be attempted');

    // Second call should hit the short-lived cache rather than OpenRouter again.
    $before = count($GLOBALS['awpt_test_http_requests']);
    $cached = new OpenRouterBilling()->get_summary();
    $after = count($GLOBALS['awpt_test_http_requests']);

    Assert::same(0.42, $cached['usage_daily'] ?? null, 'cached summary should retain daily usage');
    Assert::same($before, $after, 'cached billing summary should avoid a second network fetch');

    // Explicit refresh should bypass the cache.
    $before_refresh = count($GLOBALS['awpt_test_http_requests']);
    $refreshed = new OpenRouterBilling()->refresh_summary();
    $after_refresh = count($GLOBALS['awpt_test_http_requests']);

    Assert::same(0.42, $refreshed['usage_daily'] ?? null, 'refresh should still return daily usage');
    Assert::true($after_refresh > $before_refresh, 'refresh should re-query OpenRouter');
}

test_openrouter_billing_fetches_key_usage_and_optional_balance();

function test_openrouter_billing_survives_credits_endpoint_forbidden(): void {
    awpt_test_reset_state();
    update_option('awpt_provider', 'openrouter');
    update_option('awpt_openrouter_api_key', 'sk-or-test');

    $GLOBALS['awpt_test_http_get_response'] = static function (string $url): array {
        if (str_contains($url, '/api/v1/key')) {
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'data' => [
                        'usage' => 1.0,
                        'usage_daily' => 0.1,
                        'usage_weekly' => 0.2,
                        'usage_monthly' => 0.5,
                        'limit' => null,
                        'limit_remaining' => null,
                        'is_free_tier' => true,
                    ],
                ]),
            ];
        }

        return [
            'response' => ['code' => 403],
            'body' => wp_json_encode([
                'error' => [
                    'code' => 403,
                    'message' => 'Only management keys can perform this operation',
                ],
            ]),
        ];
    };

    $summary = new OpenRouterBilling()->get_summary();

    Assert::true($summary['available'] ?? false, 'key usage alone should still make billing available');
    Assert::same(0.1, $summary['usage_daily'] ?? null, 'daily usage should still be present');
    Assert::true(
        array_key_exists('balance', $summary) && null === $summary['balance'],
        'balance should stay null without credits access',
    );
    Assert::true($summary['is_free_tier'] ?? false, 'free-tier flag should pass through');
}

test_openrouter_billing_survives_credits_endpoint_forbidden();

function test_openrouter_billing_returns_error_when_key_endpoint_fails(): void {
    awpt_test_reset_state();
    update_option('awpt_provider', 'openrouter');
    update_option('awpt_openrouter_api_key', 'sk-or-test');
    $GLOBALS['awpt_test_http_get_response'] = [
        'response' => ['code' => 401],
        'body' => wp_json_encode([
            'error' => [
                'code' => 401,
                'message' => 'Missing Authentication header',
            ],
        ]),
    ];

    $summary = new OpenRouterBilling()->get_summary();

    Assert::true(is_wp_error($summary), 'auth failures on the key endpoint should surface as WP_Error');
    Assert::same(
        'awpt_openrouter_billing_request_failed',
        is_wp_error($summary) ? $summary->get_error_code() : '',
        'error code should identify the billing request failure',
    );
}

test_openrouter_billing_returns_error_when_key_endpoint_fails();
