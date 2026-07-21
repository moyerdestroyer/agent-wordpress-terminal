<?php

/**
 * Provider call telemetry repository.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

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
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $wpdb = WpDb::get();

        if (!method_exists($wpdb, 'insert')) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'awpt_provider_calls',
            [
                'session_id' => $session_id,
                'provider' => (string) ($call['provider'] ?? ''),
                'model' => (string) ($result['model'] ?? ''),
                'turn_id' => '' !== (string) ($call['turn_id'] ?? '') ? sanitize_key((string) $call['turn_id']) : null,
                'tool_round' => (int) ($call['tool_round'] ?? 0),
                'outcome' => null !== $error ? 'error' : (string) ($call['outcome'] ?? 'success'),
                'error_code' => null !== $error ? $error->get_error_code() : '',
                'completion_budget' => (int) ($call['budget'] ?? 0),
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                'duration_ms' => (int) ($call['duration_ms'] ?? 0),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s'],
        );
    }

    /** @return list<array<string, mixed>> */
    public function list_for_session(int $session_id, int $limit = 20): array {
        $wpdb = WpDb::get();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider, model, turn_id, tool_round, outcome, error_code, completion_budget, prompt_tokens, completion_tokens, total_tokens, duration_ms, created_at FROM {$wpdb->prefix}awpt_provider_calls WHERE session_id = %d ORDER BY id DESC LIMIT %d",
                $session_id,
                $limit,
            ),
            ARRAY_A,
        );
        return is_array($rows) ? array_reverse($rows) : [];
    }
}
