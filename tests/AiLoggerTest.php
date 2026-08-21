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

function test_ai_logger_prompt_audit_labels_evaluate_and_focused_act(): void {
    $evaluate = AiLogger::prompt_audit(
        [
            ['role' => 'system', 'content' => 'You are AWPT.'],
            ['role' => 'user', 'content' => "[awpt:improve_evaluate]\nEvaluate this focused page."],
        ],
        [
            ['type' => 'function', 'function' => ['name' => 'wpab__awpt__read-block-tree']],
        ],
        ['log_phase' => 'direct', 'tool_choice' => 'auto'],
    );
    Assert::same('improve_evaluate', $evaluate['kind'] ?? '', 'evaluate hops are labeled');
    Assert::true($evaluate['flags']['has_evaluate_marker'] ?? false, 'evaluate marker is flagged');
    Assert::false($evaluate['flags']['has_unit_fence'] ?? true, 'evaluate is not a focused unit');
    Assert::same(['wpab__awpt__read-block-tree'], $evaluate['tool_names'] ?? null, 'offered tools are listed');
    Assert::same(2, $evaluate['context_manifest']['message_count'] ?? 0, 'context manifest counts messages');
    Assert::same(64, strlen((string) ($evaluate['messages'][0]['prefix_sha256'] ?? '')), 'prefix is fingerprinted');
    Assert::true(
        (int) ($evaluate['context_manifest']['tool_schema_chars'] ?? 0) > 0,
        'context manifest measures the tool schema',
    );

    $act = AiLogger::prompt_audit(
        [
            ['role' => 'system', 'content' => 'You are AWPT.'],
            [
                'role' => 'user',
                'content' =>
                    "[awpt:improve_act]\nExecute only this unit.\n\n## Unit\n```awpt-unit\n"
                        . '{"id":"links","op":"batch","title":"Merge split links","paths":["2","3"]}'
                        . "\n```",
            ],
        ],
        [
            ['type' => 'function', 'function' => ['name' => 'wpab__awpt__propose-block-batch-update']],
        ],
        ['log_phase' => 'compose', 'tool_choice' => ['type' => 'function', 'function' => ['name' => 'x']]],
    );
    Assert::same('improve_act', $act['kind'] ?? '', 'act hops are labeled');
    Assert::true($act['flags']['has_unit_fence'] ?? false, 'focused unit fence is flagged');
    Assert::false($act['flags']['has_full_plan_heading'] ?? true, 'focused act must not carry ## Plan');
    Assert::same('links', $act['unit']['id'] ?? '', 'unit id is lifted into the audit');
    Assert::same('batch', $act['unit']['op'] ?? '', 'unit op is lifted into the audit');
    Assert::same(['2', '3'], $act['unit']['paths'] ?? null, 'unit paths are lifted into the audit');
    Assert::same('exact', $act['tool_choice'] ?? '', 'forced single-tool choice is recorded as exact');

    $retained = AiLogger::prompt_audit([
        ['role' => 'user', 'content' => '[awpt:improve_evaluate] old evaluate request'],
        ['role' => 'assistant', 'content' => 'old plan'],
        ['role' => 'user', 'content' => '[awpt:improve_act] current act request'],
    ], []);
    Assert::same('improve_act', $retained['kind'] ?? '', 'latest user marker wins over retained evaluate history');
}

test_ai_logger_redacts_secrets_and_images();
test_ai_logger_writes_ndjson_file();
test_ai_logger_prompt_audit_labels_evaluate_and_focused_act();
