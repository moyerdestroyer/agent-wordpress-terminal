<?php

/**
 * Tests for AWPT\Support\AiLogger redaction helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\AiLogger;

function test_ai_logger_redacts_secrets_and_images(): void {
    awpt_test_reset_state();

    $payload = AiLogger::sanitize_value([
        'authorization' => 'Bearer sk-secret-key',
        'api_key' => 'should-not-appear',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Describe this',
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:image/png;base64,' . str_repeat('A', 5000),
                        ],
                    ],
                ],
            ],
        ],
        'note' => 'Bearer sk-live-abc123 should go',
    ]);

    Assert::true(is_array($payload), 'sanitize_value should return an array');
    Assert::same('[redacted]', $payload['authorization'] ?? null, 'authorization values must be redacted');
    Assert::same('[redacted]', $payload['api_key'] ?? null, 'api_key values must be redacted');
    Assert::true(
        is_string($payload['note'] ?? null) && !str_contains((string) $payload['note'], 'sk-live-abc123'),
        'Bearer tokens embedded in strings must be redacted',
    );

    $image = $payload['messages'][0]['content'][1]['image_url']['url'] ?? '';
    Assert::true(is_string($image) && str_contains($image, '[omitted'), 'image data URLs must be omitted');
    Assert::false(str_contains((string) $image, str_repeat('A', 100)), 'raw image bytes must not remain');
}

function test_ai_logger_writes_ndjson_file(): void {
    awpt_test_reset_state();
    $dir = sys_get_temp_dir() . '/awpt-ai-logger-test-' . wp_generate_password(8, false);
    $GLOBALS['awpt_test_upload_basedir'] = $dir;

    AiLogger::log(AiLogger::EVENT_PROVIDER_COMPLETE, [
        'session_id' => 9,
        'turn_id' => 'turn_abc',
        'provider' => 'OpenAI',
        'model' => 'chat-latest',
        'outcome' => 'success',
        'duration_ms' => 12,
        'request' => [
            'messages' => [['role' => 'user', 'content' => 'hello']],
            'options' => ['api_key' => 'sk-should-redact'],
        ],
        'response' => ['content' => 'hi'],
        'meta' => ['endpoint' => 'https://api.openai.com/v1/chat/completions'],
    ]);

    $path = $dir . '/awpt-logs/ai-' . gmdate('Y-m-d') . '.log';
    Assert::true(is_file($path), 'AiLogger should write an NDJSON file under uploads/awpt-logs');
    $line = trim((string) file_get_contents($path));
    $decoded = json_decode($line, true);
    Assert::true(is_array($decoded), 'each log line should be valid JSON');
    Assert::same('provider.complete', $decoded['event'] ?? '', 'event name should be preserved');
    Assert::same(9, (int) ($decoded['session_id'] ?? 0), 'session_id should be preserved');
    Assert::same('[redacted]', $decoded['request']['options']['api_key'] ?? null, 'file logs must redact API keys');

    // Cleanup.
    @unlink($path);
    @rmdir($dir . '/awpt-logs');
    @rmdir($dir);
    unset($GLOBALS['awpt_test_upload_basedir']);
}

test_ai_logger_redacts_secrets_and_images();
test_ai_logger_writes_ndjson_file();
