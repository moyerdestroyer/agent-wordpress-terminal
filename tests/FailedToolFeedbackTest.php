<?php

/**
 * Failed tool feedback shaping for provider retries.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\FailedToolFeedback;
use AWPT\Agent\ToolResultFormatter;

function test_failed_tool_feedback_promotes_retry_fields(): void {
    $payload = FailedToolFeedback::for_provider('awpt/propose-pattern-replace', [
        'error' => 'pattern_text_updates must use dotted numeric block_path values.',
        'error_code' => 'awpt_pattern_text_path_invalid',
        'error_data' => [
            'recovery' => 'Retry with this preparation_id and pattern_text_updates for editable_slots.',
            'preparation_id' => 'prep-abc',
            'editable_slots' => [
                ['block_path' => '0.1.0', 'role' => 'heading', 'excerpt' => 'Section heading (h2)'],
                ['block_path' => '0.1.1', 'role' => 'body', 'excerpt' => 'Body copy'],
            ],
            'retry_example' => [
                'preparation_id' => 'prep-abc',
                'pattern_text_updates' => [['block_path' => '0.1.0', 'content' => 'Real heading']],
            ],
            'constraints' => [[
                'id' => 'pattern_text_path_invalid',
                'error_code' => 'awpt_pattern_text_path_invalid',
                'summary' => 'Use dotted paths from editable_slots.',
                'hints' => ['Copy block_path exactly.'],
                'facts' => ['preparation_id' => 'prep-abc'],
            ]],
        ],
    ]);

    Assert::same(false, $payload['ok'] ?? null, 'ok false');
    Assert::same('awpt_pattern_text_path_invalid', $payload['error_code'] ?? null, 'code');
    Assert::true(str_contains((string) ($payload['fix'] ?? ''), 'preparation_id'), 'fix from recovery');
    Assert::same('prep-abc', $payload['retry_with']['preparation_id'] ?? null, 'retry_with promoted');
    Assert::same('0.1.0', $payload['use']['editable_slots'][0]['block_path'] ?? null, 'slots in use');
    Assert::true(isset($payload['constraints'][0]['hints']), 'constraints kept');
    Assert::false(isset($payload['error_data']), 'nested error_data not dumped to provider');
    Assert::true(str_contains((string) ($payload['instruction'] ?? ''), 'failed'), 'retry instruction');
}

function test_failed_tool_feedback_transcript_includes_fix(): void {
    $shaped = FailedToolFeedback::for_provider('awpt/propose-block-batch-update', [
        'error' => 'Fingerprint mismatch.',
        'error_code' => 'awpt_block_fingerprint_mismatch',
        'error_data' => [
            'recovery' => 'Copy current_fingerprint from the latest read.',
            'current_fingerprint' => str_repeat('a', 64),
            'block_path' => '2.0',
        ],
    ]);
    $content = new ToolResultFormatter()->format_for_transcript([[
        'tool' => 'awpt/propose-block-batch-update',
        'status' => 'failed',
        'output' => $shaped,
    ]]);

    Assert::true(str_contains($content, 'Fingerprint mismatch'), 'error visible');
    Assert::true(str_contains($content, 'Fix:'), 'fix label');
    Assert::true(str_contains($content, 'current_fingerprint'), 'recovery text');
}

test_failed_tool_feedback_promotes_retry_fields();
test_failed_tool_feedback_transcript_includes_fix();
