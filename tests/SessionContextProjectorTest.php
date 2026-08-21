<?php

/** @package AWPT */

declare(strict_types=1);

use AWPT\Agent\SessionCompactionService;
use AWPT\Agent\SessionContextProjector;

final class AwptCheckpointTestProvider implements AWPT\Agent\ProviderInterface {
    public int $calls = 0;

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools, $options);
        ++$this->calls;

        return [
            'content' => wp_json_encode([
                'goal' => 'Continue the site task',
                'constraints' => ['preserve user copy'],
                'decisions' => [],
                'completed' => [],
                'unresolved' => ['verify live state'],
                'references' => ['post_id' => 7],
                'evidence' => [],
                'freshness' => 'historical',
            ]),
            'model' => 'test-model',
            'usage' => [],
        ];
    }

    public function accepts_image_input(): bool {
        return false;
    }

    public function get_name(): string {
        return 'Test';
    }
}

function test_session_context_estimate_includes_tools(): void {
    $messages = [['role' => 'system', 'content' => str_repeat('policy ', 100)]];
    $without = SessionContextProjector::estimate_request_tokens($messages);
    $with = SessionContextProjector::estimate_request_tokens($messages, [[
        'type' => 'function',
        'function' => ['name' => 'read', 'description' => str_repeat('schema ', 100)],
    ]]);
    Assert::true($with > $without, 'tool schemas count toward the context budget');
}

function test_session_turn_lock_rejects_overlap_and_recovers(): void {
    awpt_test_reset_state();
    $lock = new AWPT\Agent\SessionTurnLock();
    Assert::true($lock->acquire(7, 'one'), 'first turn acquires');
    Assert::false($lock->acquire(7, 'two'), 'overlapping turn is rejected');
    $lock->release(7);
    Assert::true($lock->acquire(7, 'three'), 'released lock recovers');
    $lock->release(7);
    Assert::true($lock->acquire(8, 'stale'), 'stale-lock fixture acquires');
    update_option('awpt_session_turn_lock_8', ['turn_id' => 'stale', 'expires' => time() - 1], false);
    Assert::true($lock->acquire(8, 'recovered'), 'expired lock recovers');
    $lock->release(8);
}

function test_session_event_projection_preserves_native_tool_linkage(): void {
    $assistant = SessionContextProjector::event_message([
        'event_type' => 'assistant_tool_calls',
        'payload' => [
            'content' => '',
            'tool_calls' => [['id' => 'call_1', 'type' => 'function']],
        ],
    ]);
    $tool = SessionContextProjector::event_message([
        'event_type' => 'tool_result',
        'call_id' => 'call_1',
        'payload' => ['content' => '{"ok":false,"fix":"retry"}'],
    ]);
    Assert::same('call_1', $assistant['tool_calls'][0]['id'] ?? null, 'assistant call ID');
    Assert::same('call_1', $tool['tool_call_id'] ?? null, 'tool result linkage');
    Assert::true(str_contains((string) ($tool['content'] ?? ''), '"ok":false'), 'failed feedback is retained');
}

function test_session_projection_keeps_durable_prefix_before_mutable_context(): void {
    awpt_test_reset_state();
    $projection = new SessionContextProjector()->project(99, [
        'stable_instructions' => 'Stable policy.',
        'dynamic_context' => 'Mutable focus post 42.',
        'fallback_messages' => [['role' => 'user', 'content' => 'Durable request.']],
    ]);
    $messages = $projection['messages'];
    Assert::true(
        str_starts_with((string) ($messages[0]['content'] ?? ''), 'Stable policy.'),
        'stable instructions lead the cacheable prefix',
    );
    Assert::same('user', $messages[1]['role'] ?? null, 'durable history follows stable policy');
    Assert::same('system', $messages[2]['role'] ?? null, 'mutable context is appended after durable history');
    Assert::true(
        str_contains((string) ($messages[2]['content'] ?? ''), 'Mutable focus post 42.'),
        'mutable context remains available',
    );
}

function test_session_compaction_creates_checkpoint_and_keeps_native_tail(): void {
    awpt_test_reset_state();
    add_filter('awpt_model_context_window', static fn(): int => 16_384);
    $messages = [['role' => 'system', 'content' => str_repeat('policy ', 400)]];

    for ($index = 0; $index < 18; ++$index) {
        $messages[] = [
            'role' => 0 === ($index % 2) ? 'user' : 'assistant',
            'content' => str_repeat('historical evidence ', 140) . $index,
        ];
    }

    $provider = new AwptCheckpointTestProvider();
    $result = new SessionCompactionService()->compact_if_needed($provider, [
        'session_id' => 7,
        'turn_id' => 'turn-2',
        'messages' => $messages,
        'tools' => [['type' => 'function', 'function' => ['name' => 'read']]],
        'max_completion_tokens' => 4_096,
    ]);
    Assert::true($result['compacted'], 'oversized history is compacted');
    Assert::same(1, $provider->calls, 'one tools-off checkpoint call');
    Assert::same(1, $result['checkpoint_id'], 'validated checkpoint is persisted');
    Assert::same('system', $result['messages'][1]['role'] ?? null, 'checkpoint precedes native tail');
    Assert::true(count($result['messages']) <= 14, 'recent tail stays bounded');
    awpt_test_reset_state();
}

test_session_context_estimate_includes_tools();
test_session_turn_lock_rejects_overlap_and_recovers();
test_session_event_projection_preserves_native_tool_linkage();
test_session_projection_keeps_durable_prefix_before_mutable_context();
test_session_compaction_creates_checkpoint_and_keeps_native_tail();
