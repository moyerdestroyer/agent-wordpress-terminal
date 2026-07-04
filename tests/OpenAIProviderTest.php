<?php

/**
 * Tests for AWPT\Agent\OpenAIProvider automatic model/key resolution.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\OpenAIProvider;

/**
 * Invoke a protected/private method for testing without triggering a network request.
 */
function awpt_test_invoke_protected(object $object, string $method): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object);
}

function test_openai_provider(): void
{
    // Model selection is automatic: no awpt_model option is involved at all.
    awpt_test_reset_state();
    update_option('awpt_model', 'this-should-never-be-used');
    $model = awpt_test_invoke_protected(new OpenAIProvider(), 'get_model');
    Assert::same('chat-latest', $model, 'OpenAIProvider should always use the automatic chat-latest model');

    // An explicit AWPT-entered key takes priority.
    awpt_test_reset_state();
    update_option('awpt_openai_api_key', 'sk-awpt-own-key');
    putenv('OPENAI_API_KEY=sk-from-env');
    $key = awpt_test_invoke_protected(new OpenAIProvider(), 'get_api_key');
    putenv('OPENAI_API_KEY');
    Assert::same('sk-awpt-own-key', $key, 'an explicit AWPT OpenAI key should take priority over a connector key');

    // With no AWPT-entered key, an already-configured WordPress Connector key (env var
    // convention) is reused automatically instead of requiring the user to re-enter it.
    awpt_test_reset_state();
    putenv('OPENAI_API_KEY=sk-from-connector-env');
    $key = awpt_test_invoke_protected(new OpenAIProvider(), 'get_api_key');
    putenv('OPENAI_API_KEY');
    Assert::same(
        'sk-from-connector-env',
        $key,
        'OpenAIProvider should reuse a WordPress Connector key when no AWPT key is set',
    );

    // With no AWPT-entered key and no connector key anywhere, an empty key means "not
    // configured" (ChatCompletionsProvider surfaces the appropriate error for this).
    awpt_test_reset_state();
    $key = awpt_test_invoke_protected(new OpenAIProvider(), 'get_api_key');
    Assert::same('', $key, 'OpenAIProvider should report no key when neither source is configured');
}

test_openai_provider();
