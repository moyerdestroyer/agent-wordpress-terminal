<?php

/**
 * Tests for AWPT\Agent\ProviderFactory provider selection.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\OpenAIProvider;
use AWPT\Agent\OpenRouterProvider;
use AWPT\Agent\ProviderFactory;
use AWPT\Agent\WordPressAIClientProvider;

function test_provider_factory(): void {
    // No connectors installed, no direct-key provider configured: the guaranteed
    // OpenRouter baseline is selected, and it reports itself as unconfigured rather
    // than erroring out unexpectedly.
    awpt_test_reset_state();
    $provider = new ProviderFactory()->make();
    Assert::instanceOf(
        OpenRouterProvider::class,
        $provider,
        'default provider (no connectors, no keys) should be OpenRouterProvider',
    );

    $result = $provider->complete([]);
    Assert::true(is_wp_error($result), 'OpenRouter provider without a key should return a WP_Error');

    if (is_wp_error($result)) {
        Assert::true(
            str_contains($result->get_error_message(), 'OpenRouter API key is not configured'),
            'OpenRouter error message should explain the missing API key',
        );
    }

    // A direct-key provider is selectable purely by saving its option — no connector
    // plugin, WordPress Core Connectors API, or AI Client is involved.
    awpt_test_reset_state();
    update_option('awpt_provider', 'openai');
    update_option('awpt_openai_api_key', 'sk-test-key');
    $provider = new ProviderFactory()->make();
    Assert::instanceOf(OpenAIProvider::class, $provider, 'awpt_provider=openai should select OpenAIProvider');

    // A ready WordPress Connector is used as an optional accelerator when explicitly
    // selected and available.
    awpt_test_reset_state();
    $GLOBALS['awpt_test_connectors'] = [
        'my_connector' => [
            'type' => 'ai_provider',
            'name' => 'My Connector',
            'authentication' => ['method' => 'none'],
        ],
    ];
    update_option('awpt_provider', 'my_connector');
    $provider = new ProviderFactory()->make();
    Assert::instanceOf(
        WordPressAIClientProvider::class,
        $provider,
        'a selected, valid WordPress connector should select WordPressAIClientProvider',
    );

    // An invalid/unregistered provider ID falls back to the guaranteed baseline instead
    // of ever failing to produce a usable provider.
    awpt_test_reset_state();
    update_option('awpt_provider', 'not_a_real_connector');
    $provider = new ProviderFactory()->make();
    Assert::instanceOf(
        OpenRouterProvider::class,
        $provider,
        'an unrecognized provider ID should fall back to OpenRouterProvider',
    );
}

test_provider_factory();
