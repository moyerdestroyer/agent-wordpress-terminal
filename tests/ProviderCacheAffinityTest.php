<?php

/**
 * Provider cache affinity unit tests.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ProviderCacheAffinity;
use AWPT\Database\ProviderCallRepository;

function test_provider_cache_affinity_key_is_session_scoped(): void {
    $first = ProviderCacheAffinity::key(['session_id' => 42, 'turn_id' => 'queue-1']);
    $second = ProviderCacheAffinity::key(['session_id' => 42, 'turn_id' => 'queue-2']);
    Assert::same($first, $second, 'turns in one session share routing affinity');
    Assert::true(str_starts_with($first, 'awpt-'), 'key is namespaced');
    Assert::false(str_contains($first, 's42'), 'raw session ID is not exposed');
    Assert::false(
        $first === ProviderCacheAffinity::key(['session_id' => 43]),
        'different sessions receive different affinity',
    );
    Assert::same('', ProviderCacheAffinity::key([]), 'empty');
}

function test_provider_cache_affinity_openrouter_fields(): void {
    $applied = ProviderCacheAffinity::apply_openrouter(['model' => 'x'], ['Authorization' => 'Bearer t'], 'opaque-s1');
    Assert::same('opaque-s1', $applied['payload']['session_id'] ?? null, 'session_id body');
    Assert::false(isset($applied['payload']['prompt_cache_key']), 'no openai field');
    Assert::false(isset($applied['headers']['x-session-id']), 'body is the single routing source');
}

function test_provider_cache_affinity_openai_fields(): void {
    $payload = ProviderCacheAffinity::apply_openai([
        'model' => 'gpt-5.6-luna',
        'messages' => [
            ['role' => 'system', 'content' => str_repeat('Stable policy. ', 100)],
            ['role' => 'system', 'content' => 'Dynamic session state.'],
        ],
    ], ['session_id' => 9]);
    Assert::true(isset($payload['prompt_cache_key']), 'prompt_cache_key');
    Assert::same('explicit', $payload['prompt_cache_options']['mode'] ?? null, 'explicit-only mode');
    Assert::same(
        'explicit',
        $payload['messages'][0]['content'][0]['prompt_cache_breakpoint']['mode'] ?? null,
        'stable system prefix breakpoint',
    );
    Assert::same('Dynamic session state.', $payload['messages'][1]['content'] ?? null, 'dynamic suffix unchanged');

    $projected = ProviderCacheAffinity::apply_openai([
        'model' => 'gpt-5.6-luna',
        'messages' => [
            ['role' => 'system', 'content' => 'Stable' . ProviderCacheAffinity::STABLE_BOUNDARY],
            ['role' => 'user', 'content' => 'Durable session history'],
            [
                'role' => 'system',
                'content' => 'Current mutable WordPress/session context (revalidate before writes): focus=42',
            ],
        ],
    ], ['session_id' => 9]);
    Assert::same(
        'explicit',
        $projected['messages'][2]['content'][0]['prompt_cache_breakpoint']['mode'] ?? null,
        'mutable-context boundary caches the complete initial tool-loop prefix',
    );

    $bounded = ProviderCacheAffinity::apply_openai([
        'model' => 'gpt-5.6-luna',
        'messages' => [[
            'role' => 'system',
            'content' => 'Stable' . ProviderCacheAffinity::STABLE_BOUNDARY . 'Dynamic',
        ]],
    ], ['session_id' => 9]);
    Assert::same('Stable', $bounded['messages'][0]['content'][0]['text'] ?? null, 'stable block');
    Assert::same('Dynamic', $bounded['messages'][0]['content'][1]['text'] ?? null, 'dynamic block follows breakpoint');

    $legacy = ProviderCacheAffinity::apply_openai([
        'model' => 'chat-latest',
        'messages' => [['role' => 'system', 'content' => 'Stable']],
    ]);
    Assert::false(isset($legacy['prompt_cache_options']), 'unsupported models remain implicit');
}

function test_provider_cache_affinity_parses_cached_tokens(): void {
    Assert::same(
        120,
        ProviderCacheAffinity::cached_tokens_from_usage([
            'prompt_tokens' => 1000,
            'prompt_tokens_details' => ['cached_tokens' => 120],
        ]),
        'openai shape',
    );
    Assert::same(
        50,
        ProviderCacheAffinity::cached_tokens_from_usage([
            'input_tokens_details' => ['cached_tokens' => 50],
        ]),
        'responses shape',
    );
    Assert::same(0, ProviderCacheAffinity::cached_tokens_from_usage(['prompt_tokens' => 10]), 'missing');
    Assert::same(
        44,
        ProviderCacheAffinity::cache_write_tokens_from_usage([
            'prompt_tokens_details' => ['cache_write_tokens' => 44],
        ]),
        'cache writes',
    );
}

function test_provider_call_telemetry_persists_cache_reads_and_writes(): void {
    $database = new class extends wpdb {
        /** @var array<string, mixed> */
        public array $inserted = [];
        /** @var list<string> */
        public array $formats = [];

        public function insert(string $table, array $data, array|string|null $format = null): int|false {
            unset($table);
            $this->inserted = $data;
            $this->formats = is_array($format) ? $format : [];

            return 1;
        }
    };
    $GLOBALS['wpdb'] = $database;
    new ProviderCallRepository()->store(4, [
        'provider' => 'OpenAI',
        'turn_id' => 'turn-1',
        'context_tokens_estimate' => 2_000,
        'checkpoint_event_id' => 9,
        'result' => [
            'model' => 'gpt-5.6-luna',
            'usage' => [
                'prompt_tokens' => 1_500,
                'prompt_tokens_details' => ['cached_tokens' => 1_000, 'cache_write_tokens' => 300],
            ],
        ],
    ]);
    Assert::same(1_000, $database->inserted['cached_tokens'] ?? null, 'cache reads persisted');
    Assert::same(300, $database->inserted['cache_write_tokens'] ?? null, 'cache writes persisted');
    Assert::same(2_000, $database->inserted['context_tokens_estimate'] ?? null, 'context estimate persisted');
    Assert::same(9, $database->inserted['checkpoint_event_id'] ?? null, 'checkpoint linkage persisted');
    Assert::same('explicit', $database->inserted['cache_mode'] ?? null, 'cache mode persisted');
    Assert::same('hit', $database->inserted['cache_status'] ?? null, 'actual cache outcome persisted');
    Assert::same(
        [
            '%d',
            '%s',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%d',
            '%s',
            '%s',
            '%d',
            '%s',
        ],
        $database->formats,
        'database formats stay aligned with telemetry columns',
    );

    new ProviderCallRepository()->store(4, [
        'provider' => 'OpenRouter',
        'result' => ['usage' => ['prompt_tokens_details' => ['cached_tokens' => 0]]],
    ]);
    Assert::same('miss', $database->inserted['cache_status'] ?? null, 'reported zero cache usage is a miss');

    new ProviderCallRepository()->store(4, [
        'provider' => 'OpenRouter',
        'result' => ['usage' => ['prompt_tokens' => 100]],
    ]);
    Assert::same('unreported', $database->inserted['cache_status'] ?? null, 'missing cache usage stays unknown');
    $GLOBALS['wpdb'] = new wpdb();
}

test_provider_cache_affinity_key_is_session_scoped();
test_provider_cache_affinity_openrouter_fields();
test_provider_cache_affinity_openai_fields();
test_provider_cache_affinity_parses_cached_tokens();
test_provider_call_telemetry_persists_cache_reads_and_writes();
