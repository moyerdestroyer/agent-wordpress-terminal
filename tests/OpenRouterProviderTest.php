<?php

/**
 * Tests for OpenRouter routing and forced tool-call payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\OpenRouterProvider;

function test_openrouter_provider_tool_routing(): void {
    awpt_test_reset_state();
    update_option('awpt_openrouter_api_key', 'test-key');
    $GLOBALS['awpt_test_http_response'] = [
        'response' => ['code' => 200],
        'body' => wp_json_encode([
            'model' => 'openai/gpt-5',
            'choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => []]]],
        ]),
    ];
    $tool_choice = [
        'type' => 'function',
        'function' => ['name' => 'awpt__propose_new_post'],
    ];
    $tools = [[
        'type' => 'function',
        'function' => [
            'name' => 'awpt__propose_new_post',
            'description' => 'Stage a page.',
            'parameters' => ['type' => 'object'],
        ],
    ]];

    new OpenRouterProvider()->complete([['role' => 'user', 'content' => 'Build a page.']], $tools, [
        'session_id' => 38,
        'tool_choice' => $tool_choice,
        'quality_route' => true,
    ]);

    $request = $GLOBALS['awpt_test_http_requests'][0] ?? [];
    $args = is_array($request['args'] ?? null) ? $request['args'] : [];
    $payload = json_decode((string) ($args['body'] ?? ''), true);
    Assert::same($tool_choice, $payload['tool_choice'] ?? null, 'OpenRouter should preserve forced tool choice');
    Assert::same(8_192, $payload['max_tokens'] ?? null, 'OpenRouter should use its portable completion limit field');
    Assert::false(
        array_key_exists('max_completion_tokens', $payload),
        'OpenRouter should not send the provider-specific completion limit alias',
    );
    Assert::same(
        true,
        $payload['provider']['require_parameters'] ?? null,
        'OpenRouter tool requests should require parameter-capable endpoints',
    );
    Assert::same(
        'openai/gpt-5.4-mini',
        $payload['model'] ?? null,
        'OpenRouter should use the balanced tool-capable default',
    );
    Assert::false(array_key_exists('plugins', $payload), 'the pinned default should not invoke router plugins');
    Assert::same('awpt-38', $payload['session_id'] ?? null, 'OpenRouter requests should retain session affinity');
}

test_openrouter_provider_tool_routing();

function test_openrouter_provider_migrates_legacy_auto_but_preserves_exact_models(): void {
    awpt_test_reset_state();
    update_option('awpt_openrouter_api_key', 'test-key');
    $GLOBALS['awpt_test_http_response'] = [
        'response' => ['code' => 200],
        'body' => wp_json_encode([
            'model' => 'test/selected',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Done.']]],
        ]),
    ];
    update_option('awpt_openrouter_model', 'openrouter/auto');
    new OpenRouterProvider()->complete([['role' => 'user', 'content' => 'Help.']]);
    $legacy_request = $GLOBALS['awpt_test_http_requests'][0] ?? [];
    $legacy_args = is_array($legacy_request['args'] ?? null) ? $legacy_request['args'] : [];
    $legacy_payload = json_decode((string) ($legacy_args['body'] ?? ''), true);
    Assert::same(
        'openai/gpt-5.4-mini',
        $legacy_payload['model'] ?? null,
        'saved legacy Auto settings should migrate to the balanced model at request time',
    );

    $GLOBALS['awpt_test_http_requests'] = [];
    update_option('awpt_openrouter_model', 'openrouter/auto-beta');
    new OpenRouterProvider()->complete([['role' => 'user', 'content' => 'Help.']]);
    $beta_request = $GLOBALS['awpt_test_http_requests'][0] ?? [];
    $beta_args = is_array($beta_request['args'] ?? null) ? $beta_request['args'] : [];
    $beta_payload = json_decode((string) ($beta_args['body'] ?? ''), true);
    Assert::same(
        'openai/gpt-5.4-mini',
        $beta_payload['model'] ?? null,
        'saved Auto Beta settings should migrate after an unsuitable routed model',
    );

    $GLOBALS['awpt_test_http_requests'] = [];
    update_option('awpt_openrouter_model', 'google/gemini-2.5-pro');
    new OpenRouterProvider()->complete([['role' => 'user', 'content' => 'Help.']]);
    $exact_request = $GLOBALS['awpt_test_http_requests'][0] ?? [];
    $exact_args = is_array($exact_request['args'] ?? null) ? $exact_request['args'] : [];
    $exact_payload = json_decode((string) ($exact_args['body'] ?? ''), true);
    Assert::same(
        'google/gemini-2.5-pro',
        $exact_payload['model'] ?? null,
        'an explicitly pinned model should remain unchanged',
    );
    Assert::false(
        array_key_exists('plugins', $exact_payload),
        'Auto Router policy fields should not be sent to an explicitly pinned model',
    );
}

test_openrouter_provider_migrates_legacy_auto_but_preserves_exact_models();
