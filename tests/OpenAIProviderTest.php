<?php

/**
 * Tests for AWPT\Agent\OpenAIProvider model/key resolution and GPT-5.6 tool payload.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\OpenAIProvider;

/**
 * Invoke a protected/private method for testing without triggering a network request.
 *
 * @param list<mixed> $args
 */
function awpt_test_invoke_protected(object $object, string $method, array $args = []): mixed {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$args);
}

function test_openai_provider(): void {
    awpt_test_reset_state();
    update_option('awpt_model', 'this-should-never-be-used');
    $model = awpt_test_invoke_protected(new OpenAIProvider(), 'get_model');
    Assert::same(OpenAIProvider::DEFAULT_MODEL, $model, 'OpenAIProvider default should be gpt-5.6-luna');

    awpt_test_reset_state();
    update_option('awpt_openai_model', 'gpt-5.6-terra');
    $model = awpt_test_invoke_protected(new OpenAIProvider(), 'get_model');
    Assert::same('gpt-5.6-terra', $model, 'awpt_openai_model option should override the default');

    // GPT-5.6 + tools on Chat Completions requires explicit reasoning_effort=none.
    $payload = awpt_test_invoke_protected(new OpenAIProvider(), 'decorate_request_payload', [
        [
            'model' => 'gpt-5.6-terra',
            'tools' => [['type' => 'function', 'function' => ['name' => 'x']]],
            'messages' => [['role' => 'system', 'content' => str_repeat('Stable. ', 200)]],
        ],
        ['session_id' => 1, 'turn_id' => 't1'],
    ]);
    Assert::same('none', $payload['reasoning_effort'] ?? null, 'gpt-5.6 + tools must set reasoning_effort=none');
    Assert::true(isset($payload['prompt_cache_key']), 'OpenAI decorate should still apply cache affinity');

    $legacy = awpt_test_invoke_protected(new OpenAIProvider(), 'decorate_request_payload', [
        [
            'model' => 'chat-latest',
            'tools' => [['type' => 'function', 'function' => ['name' => 'x']]],
            'messages' => [['role' => 'system', 'content' => 'Stable.']],
        ],
        ['session_id' => 1, 'turn_id' => 't1'],
    ]);
    Assert::false(
        array_key_exists('reasoning_effort', $legacy),
        'non-5.6 models should not force reasoning_effort=none',
    );

    awpt_test_reset_state();
    update_option('awpt_openai_api_key', 'sk-awpt-own-key');
    putenv('OPENAI_API_KEY=sk-from-env');
    $key = awpt_test_invoke_protected(new OpenAIProvider(), 'get_api_key');
    putenv('OPENAI_API_KEY');
    Assert::same('sk-awpt-own-key', $key, 'an explicit AWPT OpenAI key should take priority over a connector key');

    awpt_test_reset_state();
    putenv('OPENAI_API_KEY=sk-from-connector-env');
    $key = awpt_test_invoke_protected(new OpenAIProvider(), 'get_api_key');
    putenv('OPENAI_API_KEY');
    Assert::same(
        'sk-from-connector-env',
        $key,
        'OpenAIProvider should reuse a WordPress Connector key when no AWPT key is set',
    );

    awpt_test_reset_state();
    $key = awpt_test_invoke_protected(new OpenAIProvider(), 'get_api_key');
    Assert::same('', $key, 'OpenAIProvider should report no key when neither source is configured');
}

test_openai_provider();
