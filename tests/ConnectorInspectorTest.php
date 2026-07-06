<?php

/**
 * Tests for AWPT\Support\ConnectorInspector.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\ConnectorInspector;

/**
 * Exercises the real WordPress 7.0 Connectors API authentication contract:
 * `authentication.method` of `none` never needs credentials; `api_key` connectors are
 * checked via env var, then PHP constant, then DB option (see the WordPress Core
 * "Introducing the Connectors API in WordPress 7.0" dev note).
 */
function test_connector_inspector(): void {
    $inspector = new ConnectorInspector();

    // `none` authentication is always ready, regardless of any stored credentials.
    awpt_test_reset_state();
    $status = $inspector->build_status('local_none', [
        'type' => 'ai_provider',
        'authentication' => ['method' => 'none'],
    ]);
    Assert::true($status['ready'], 'none-auth connector should be ready with no credentials');

    // api_key connector with credentials in an environment variable (custom name).
    awpt_test_reset_state();
    putenv('AWPT_TEST_CUSTOM_KEY=sk-from-env');
    $status = $inspector->build_status('anthropic', [
        'type' => 'ai_provider',
        'authentication' => [
            'method' => 'api_key',
            'env_var_name' => 'AWPT_TEST_CUSTOM_KEY',
        ],
    ]);
    putenv('AWPT_TEST_CUSTOM_KEY');
    Assert::true($status['ready'], 'api_key connector should be ready when the named env var is set');
    Assert::same('ready', $status['status'], 'ready connector status should be "ready"');

    // api_key connector with credentials via the default {PROVIDER_ID}_API_KEY env var.
    awpt_test_reset_state();
    putenv('GOOGLE_API_KEY=sk-google-env');
    $status = $inspector->build_status('google', [
        'type' => 'ai_provider',
        'authentication' => ['method' => 'api_key'],
    ]);
    putenv('GOOGLE_API_KEY');
    Assert::true($status['ready'], 'api_key connector should fall back to the {PROVIDER_ID}_API_KEY env var');

    // api_key connector with credentials via a PHP constant.
    awpt_test_reset_state();
    define('AWPT_TEST_CONNECTOR_CONSTANT_KEY', 'sk-from-constant');
    $status = $inspector->build_status('openai_test', [
        'type' => 'ai_provider',
        'authentication' => [
            'method' => 'api_key',
            'constant_name' => 'AWPT_TEST_CONNECTOR_CONSTANT_KEY',
        ],
    ]);
    Assert::true($status['ready'], 'api_key connector should be ready when the named PHP constant is defined');

    // api_key connector with credentials in the database option (default setting_name).
    awpt_test_reset_state();
    update_option('connectors_ai_my_connector_api_key', 'sk-from-option');
    $status = $inspector->build_status('my_connector', [
        'type' => 'ai_provider',
        'authentication' => ['method' => 'api_key'],
    ]);
    Assert::true($status['ready'], 'api_key connector should be ready when the default DB option is set');

    // api_key connector with no credentials anywhere is not ready, and reports why.
    awpt_test_reset_state();
    $status = $inspector->build_status('unconfigured', [
        'type' => 'ai_provider',
        'authentication' => ['method' => 'api_key'],
    ]);
    Assert::false($status['ready'], 'api_key connector with no credentials should not be ready');
    Assert::same('not_configured', $status['status'], 'unconfigured connector status should be "not_configured"');

    // Missing `authentication` metadata entirely should never be silently treated as ready.
    awpt_test_reset_state();
    $status = $inspector->build_status('malformed', ['type' => 'ai_provider']);
    Assert::false($status['ready'], 'connector with no authentication metadata should not be ready');

    // A connector's own `plugin.is_active` callback (the documented Core contract) is honored.
    awpt_test_reset_state();
    $status = $inspector->build_status('callback_active', [
        'type' => 'ai_provider',
        'authentication' => ['method' => 'none'],
        'plugin' => ['is_active' => static fn(): bool => false],
    ]);
    Assert::false($status['active'], 'connector should be inactive when its plugin.is_active callback returns false');
    Assert::same('inactive', $status['status'], 'inactive connector status should be "inactive"');
}

test_connector_inspector();
