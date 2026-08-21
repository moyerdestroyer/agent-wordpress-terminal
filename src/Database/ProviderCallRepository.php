<?php

/**
 * Provider call telemetry repository.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Agent\ProviderCacheAffinity;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/** Persists lightweight, non-secret provider-call telemetry. */
final class ProviderCallRepository {
    /** @param array<string, mixed> $call */
    public function store(int $session_id, array $call): void {
        $raw_result = $call['result'] ?? null;
        $result = is_array($raw_result) ? $raw_result : [];
        $error = is_wp_error($raw_result) ? $raw_result : null;
        $usage = ArrayKey::as_map($result['usage'] ?? null);
        $wpdb = WpDb::get();

        if (!method_exists($wpdb, 'insert')) {
            return;
        }

        $prompt_tokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion_tokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $total_tokens = (int) ($usage['total_tokens'] ?? ($prompt_tokens + $completion_tokens));
        $cached_tokens = ProviderCacheAffinity::cached_tokens_from_usage($usage);
        $cache_write_tokens = ProviderCacheAffinity::cache_write_tokens_from_usage($usage);
        $model = (string) ($result['model'] ?? '');
        $cache_mode = match (true) {
            'OpenAI' === (string) ($call['provider'] ?? '') && str_starts_with(strtolower($model), 'gpt-5.6')
                => 'explicit',
            'OpenRouter' === (string) ($call['provider'] ?? '') => 'affinity',
            default => 'native',
        };
        $prompt_details = ArrayKey::as_map($usage['prompt_tokens_details'] ?? null);
        $input_details = ArrayKey::as_map($usage['input_tokens_details'] ?? null);
        $cache_reported =
            array_key_exists('cached_tokens', $usage)
            || array_key_exists('cached_tokens', $prompt_details)
            || array_key_exists('cached_tokens', $input_details);
        $cache_status = match (true) {
            $cached_tokens > 0 => 'hit',
            $cache_reported => 'miss',
            default => 'unreported',
        };

        $wpdb->insert(
            $wpdb->prefix . 'awpt_provider_calls',
            [
                'session_id' => $session_id,
                'provider' => (string) ($call['provider'] ?? ''),
                'model' => $model,
                'turn_id' => '' !== (string) ($call['turn_id'] ?? '') ? sanitize_key((string) $call['turn_id']) : null,
                'tool_round' => (int) ($call['tool_round'] ?? 0),
                'outcome' => null !== $error ? 'error' : (string) ($call['outcome'] ?? 'success'),
                'error_code' => null !== $error ? $error->get_error_code() : '',
                'completion_budget' => (int) ($call['budget'] ?? 0),
                'prompt_tokens' => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'total_tokens' => $total_tokens,
                'cached_tokens' => $cached_tokens,
                'cache_write_tokens' => $cache_write_tokens,
                'context_tokens_estimate' => (int) ($call['context_tokens_estimate'] ?? 0),
                'checkpoint_event_id' => (int) ($call['checkpoint_event_id'] ?? 0) > 0
                    ? (int) $call['checkpoint_event_id']
                    : null,
                'cache_mode' => $cache_mode,
                'cache_status' => $cache_status,
                'duration_ms' => (int) ($call['duration_ms'] ?? 0),
                'created_at' => current_time('mysql'),
            ],
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
        );
    }

    /** @return list<array<string, mixed>> */
    public function list_for_session(int $session_id, int $limit = 20): array {
        $wpdb = WpDb::get();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider, model, turn_id, tool_round, outcome, error_code, completion_budget,
                    prompt_tokens, completion_tokens, total_tokens, cached_tokens, cache_write_tokens,
                    context_tokens_estimate, checkpoint_event_id, cache_mode, cache_status, duration_ms, created_at
                FROM {$wpdb->prefix}awpt_provider_calls WHERE session_id = %d ORDER BY id DESC LIMIT %d",
                $session_id,
                $limit,
            ),
            ARRAY_A,
        );

        return is_array($rows) ? array_reverse($rows) : [];
    }

    /**
     * Sum token usage for every provider round in a session (eval + act).
     *
     * @return array{prompt_tokens: int, cached_tokens: int, cache_write_tokens: int, completion_tokens: int, rounds: int, cache_hit_rate: float|null, cache_write_rate: float|null}
     */
    public function session_token_totals(int $session_id): array {
        $empty = [
            'prompt_tokens' => 0,
            'cached_tokens' => 0,
            'cache_write_tokens' => 0,
            'completion_tokens' => 0,
            'rounds' => 0,
            'cache_hit_rate' => null,
            'cache_write_rate' => null,
        ];

        if ($session_id <= 0) {
            return $empty;
        }

        $wpdb = WpDb::get();

        if (!method_exists($wpdb, 'get_row')) {
            return $empty;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS rounds,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(cached_tokens), 0) AS cached_tokens,
                    COALESCE(SUM(cache_write_tokens), 0) AS cache_write_tokens,
                    COALESCE(SUM(completion_tokens), 0) AS completion_tokens
                FROM {$wpdb->prefix}awpt_provider_calls
                WHERE session_id = %d", $session_id), ARRAY_A);

        if (!is_array($row)) {
            return $empty;
        }

        $prompt = (int) ($row['prompt_tokens'] ?? 0);
        $cached = (int) ($row['cached_tokens'] ?? 0);
        $written = (int) ($row['cache_write_tokens'] ?? 0);

        return [
            'prompt_tokens' => $prompt,
            'cached_tokens' => $cached,
            'cache_write_tokens' => $written,
            'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
            'rounds' => (int) ($row['rounds'] ?? 0),
            'cache_hit_rate' => $prompt > 0 ? round((100 * $cached) / $prompt, 1) : null,
            'cache_write_rate' => $prompt > 0 ? round((100 * $written) / $prompt, 1) : null,
        ];
    }

    /**
     * Sum token usage for one Improve/chat turn (all tool rounds).
     *
     * @return array{prompt_tokens: int, cached_tokens: int, cache_write_tokens: int, completion_tokens: int, rounds: int, cache_hit_rate: float|null, cache_write_rate: float|null}
     */
    public function turn_token_totals(int $session_id, string $turn_id): array {
        $empty = [
            'prompt_tokens' => 0,
            'cached_tokens' => 0,
            'cache_write_tokens' => 0,
            'completion_tokens' => 0,
            'rounds' => 0,
            'cache_hit_rate' => null,
            'cache_write_rate' => null,
        ];

        if ($session_id <= 0 || '' === $turn_id) {
            return $empty;
        }

        $wpdb = WpDb::get();

        if (!method_exists($wpdb, 'get_row')) {
            return $empty;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS rounds,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(cached_tokens), 0) AS cached_tokens,
                    COALESCE(SUM(cache_write_tokens), 0) AS cache_write_tokens,
                    COALESCE(SUM(completion_tokens), 0) AS completion_tokens
                FROM {$wpdb->prefix}awpt_provider_calls
                WHERE session_id = %d AND turn_id = %s",
                $session_id,
                sanitize_key($turn_id),
            ),
            ARRAY_A,
        );

        if (!is_array($row)) {
            return $empty;
        }

        $prompt = (int) ($row['prompt_tokens'] ?? 0);
        $cached = (int) ($row['cached_tokens'] ?? 0);
        $written = (int) ($row['cache_write_tokens'] ?? 0);

        return [
            'prompt_tokens' => $prompt,
            'cached_tokens' => $cached,
            'cache_write_tokens' => $written,
            'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
            'rounds' => (int) ($row['rounds'] ?? 0),
            'cache_hit_rate' => $prompt > 0 ? round((100 * $cached) / $prompt, 1) : null,
            'cache_write_rate' => $prompt > 0 ? round((100 * $written) / $prompt, 1) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function latest_for_turn(int $session_id, string $turn_id): ?array {
        if ($session_id <= 0 || '' === $turn_id) {
            return null;
        }

        $wpdb = WpDb::get();

        if (!method_exists($wpdb, 'get_row')) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT provider, model, tool_round, outcome, error_code, completion_budget,
                    prompt_tokens, completion_tokens, total_tokens, cached_tokens, cache_write_tokens,
                    context_tokens_estimate, checkpoint_event_id, cache_mode, cache_status, duration_ms, created_at
                FROM {$wpdb->prefix}awpt_provider_calls
                WHERE session_id = %d AND turn_id = %s
                ORDER BY id DESC
                LIMIT 1",
                $session_id,
                $turn_id,
            ),
            ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }
}
