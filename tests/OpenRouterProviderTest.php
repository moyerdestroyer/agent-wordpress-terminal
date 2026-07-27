<?php

/**
 * Tests for OpenRouter routing and forced tool-call payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\OpenRouterProvider;
use AWPT\Agent\OpenRouterVisionProvider;

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

    $requests = array_values(array_filter(
        $GLOBALS['awpt_test_http_requests'],
        static fn(array $request): bool => 'https://openrouter.ai/api/v1/chat/completions' === ($request['url'] ?? ''),
    ));
    $request = $requests[0] ?? [];
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
        'deepseek/deepseek-v4-pro',
        $payload['model'] ?? null,
        'OpenRouter should use the DeepSeek V4 Pro default',
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
        'deepseek/deepseek-v4-pro',
        $legacy_payload['model'] ?? null,
        'saved legacy Auto settings should migrate to DeepSeek V4 Pro at request time',
    );

    $GLOBALS['awpt_test_http_requests'] = [];
    update_option('awpt_openrouter_model', 'openrouter/auto-beta');
    new OpenRouterProvider()->complete([['role' => 'user', 'content' => 'Help.']]);
    $beta_request = $GLOBALS['awpt_test_http_requests'][0] ?? [];
    $beta_args = is_array($beta_request['args'] ?? null) ? $beta_request['args'] : [];
    $beta_payload = json_decode((string) ($beta_args['body'] ?? ''), true);
    Assert::same(
        'deepseek/deepseek-v4-pro',
        $beta_payload['model'] ?? null,
        'saved Auto Beta settings should migrate to DeepSeek V4 Pro',
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

function test_openrouter_provider_discovers_and_caches_image_capability(): void {
    awpt_test_reset_state();
    update_option('awpt_openrouter_model', 'example/vision-model');
    $GLOBALS['awpt_test_http_get_response'] = [
        'response' => ['code' => 200],
        'body' => wp_json_encode([
            'data' => [
                'architecture' => ['input_modalities' => ['text', 'image']],
            ],
        ]),
    ];
    $provider = new OpenRouterProvider();

    Assert::true($provider->accepts_image_input(), 'OpenRouter metadata should identify image-capable models');
    Assert::true($provider->accepts_image_input(), 'cached capability should preserve the discovered result');
    $get_requests = array_values(array_filter(
        $GLOBALS['awpt_test_http_requests'],
        static fn(array $request): bool => 'GET' === ($request['method'] ?? ''),
    ));
    Assert::same(1, count($get_requests), 'model capabilities should be cached between provider checks');
}

test_openrouter_provider_discovers_and_caches_image_capability();

function test_openrouter_provider_strips_images_for_deepseek_before_request(): void {
    awpt_test_reset_state();
    update_option('awpt_openrouter_api_key', 'test-key');
    update_option('awpt_openrouter_model', 'deepseek/deepseek-v4-pro');
    $GLOBALS['awpt_test_http_response'] = [
        'response' => ['code' => 200],
        'body' => wp_json_encode([
            'model' => 'deepseek/deepseek-v4-pro',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
        ]),
    ];

    new OpenRouterProvider()->complete([
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Use attachment #126 in the hero.'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,aaa']],
            ],
        ],
    ]);

    $requests = array_values(array_filter(
        $GLOBALS['awpt_test_http_requests'],
        static fn(array $request): bool => 'https://openrouter.ai/api/v1/chat/completions' === ($request['url'] ?? ''),
    ));
    $request = $requests[0] ?? [];
    $args = is_array($request['args'] ?? null) ? $request['args'] : [];
    $payload = json_decode((string) ($args['body'] ?? ''), true);
    $content = $payload['messages'][0]['content'] ?? null;
    Assert::true(is_string($content), 'DeepSeek payloads should flatten multimodal content to text');
    Assert::true(
        is_string($content) && str_contains($content, 'attachment #126'),
        'text attachment instructions must survive image stripping',
    );
    Assert::false(
        is_string($content) && str_contains($content, 'data:image'),
        'image data must not be sent to DeepSeek',
    );
    Assert::same(1, count($requests), 'non-vision models should not waste a failed multimodal hop');
}

test_openrouter_provider_strips_images_for_deepseek_before_request();

function test_openrouter_vision_provider_uses_auto_without_tool_routing_constraints(): void {
    awpt_test_reset_state();
    update_option('awpt_openrouter_api_key', 'test-key');
    $GLOBALS['awpt_test_http_response'] = [
        'response' => ['code' => 200],
        'body' => wp_json_encode([
            'model' => 'google/vision-selected',
            'choices' => [['message' => ['role' => 'assistant', 'content' => '{"items":[]}']]],
        ]),
    ];

    new OpenRouterVisionProvider()->complete([[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Describe this image.'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,aaa']],
        ],
    ]]);

    $request = $GLOBALS['awpt_test_http_requests'][0] ?? [];
    $args = is_array($request['args'] ?? null) ? $request['args'] : [];
    $payload = json_decode((string) ($args['body'] ?? ''), true);
    Assert::same('openrouter/auto', $payload['model'] ?? null, 'vision sidecar should use automatic routing');
    Assert::false(array_key_exists('tools', $payload), 'vision sidecar should remain tool-free');
    Assert::false(
        array_key_exists('provider', $payload),
        'vision sidecar should not require tool parameters from candidate models',
    );
}

test_openrouter_vision_provider_uses_auto_without_tool_routing_constraints();
