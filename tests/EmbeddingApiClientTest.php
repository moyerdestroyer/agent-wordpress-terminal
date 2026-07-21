<?php

/**
 * Tests for provider-specific embeddings requests and errors.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\EmbeddingApiClient;
use AWPT\Knowledge\EmbeddingService;

function test_embedding_model_is_normalized_for_openai(): void {
    awpt_test_reset_state();
    update_option('awpt_openai_api_key', 'sk-test');
    $client = new EmbeddingApiClient();

    Assert::same(
        'text-embedding-3-small',
        $client->model_for_request('openai/text-embedding-3-small'),
        'OpenAI requests should not use the OpenRouter provider prefix',
    );
}

function test_embedding_model_is_normalized_for_openrouter(): void {
    awpt_test_reset_state();
    update_option('awpt_openrouter_api_key', 'sk-or-test');
    $client = new EmbeddingApiClient();

    Assert::same(
        'openai/text-embedding-3-small',
        $client->model_for_request('text-embedding-3-small'),
        'OpenRouter requests should use its provider-prefixed model id',
    );
}

function test_embedding_api_error_is_persisted(): void {
    awpt_test_reset_state();
    update_option('awpt_openai_api_key', 'sk-test');
    $GLOBALS['awpt_test_http_response'] = [
        'response' => ['code' => 400],
        'body' => wp_json_encode(['error' => ['message' => 'Unknown model.']]),
    ];

    $vectors = new EmbeddingApiClient()->request('text-embedding-3-small', ['Hello']);

    Assert::same([null], $vectors, 'failed embedding requests should retain keyword-only fallback');
    Assert::true(
        str_contains((string) get_option(EmbeddingService::OPTION_LAST_ERROR, ''), 'Unknown model.'),
        'provider errors should be available in Knowledge status',
    );
}

test_embedding_model_is_normalized_for_openai();
test_embedding_model_is_normalized_for_openrouter();
test_embedding_api_error_is_persisted();
