<?php

/**
 * Projects durable session events into a bounded provider working set.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\SessionEventRepository;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class SessionContextProjector {
    private const DEFAULT_CONTEXT_WINDOW = 128_000;
    private const SAFETY_TOKENS = 8_192;

    private SessionEventRepository $events;

    public function __construct(?SessionEventRepository $events = null) {
        $this->events = $events ?? new SessionEventRepository();
    }

    /**
     * @param array{stable_instructions?: string, dynamic_context?: string, fallback_messages?: array<int, array<string, mixed>>, history_limit?: int, tools?: array<int, array<string, mixed>>, model?: string, max_completion_tokens?: int} $context
     * @return array{messages: array<int, array<string, mixed>>, diagnostics: array<string, mixed>}
     */
    public function project(int $session_id, array $context): array {
        $stable_instructions = $context['stable_instructions'] ?? '';
        $dynamic_context = $context['dynamic_context'] ?? '';
        $fallback_messages = self::maps($context['fallback_messages'] ?? null);
        $history_limit = (int) ($context['history_limit'] ?? 30);
        $tools = self::maps($context['tools'] ?? null);
        $model = $context['model'] ?? '';
        $max_completion_tokens = (int) ($context['max_completion_tokens'] ?? 8_192);
        $events = $this->events->list_for_projection($session_id);
        $latest_checkpoint_index = null;
        $covered_through = 0;

        foreach ($events as $index => $event) {
            if ('checkpoint' !== (string) ($event['event_type'] ?? '')) {
                continue;
            }

            $latest_checkpoint_index = $index;
            $covered_through = (int) ($event['covers_through_event_id'] ?? 0);
        }

        if (null !== $latest_checkpoint_index) {
            $events = array_values(array_filter(
                $events,
                static fn(array $event, int $index): bool => (
                    $index === $latest_checkpoint_index
                    || (int) ($event['id'] ?? 0) > $covered_through
                ),
                ARRAY_FILTER_USE_BOTH,
            ));
        }
        /** @var array<int, array<string, mixed>> $tail */
        $tail = [];
        $pruned = 0;

        foreach ($events as $event) {
            $message = self::event_message($event);

            if (null !== $message) {
                $tail[] = $message;
            }
        }

        if ([] === $tail) {
            $tail = array_slice($fallback_messages, -max(4, min(40, $history_limit)));
        }

        /** @var array<int, array<string, mixed>> $messages */
        $messages = [
            [
                'role' => 'system',
                'content' => $stable_instructions . ProviderCacheAffinity::STABLE_BOUNDARY,
            ],
            ...$tail,
        ];

        // Volatile state belongs after the durable event prefix. Tool-loop
        // requests can now append messages without rebuilding or moving the
        // expensive session history that providers are able to cache.
        if ('' !== trim($dynamic_context)) {
            $messages[] = [
                'role' => 'system',
                'content' =>
                    "Current mutable WordPress/session context (revalidate before writes):\n" . $dynamic_context,
            ];
        }
        $context_window = max(
            16_384,
            (int) apply_filters('awpt_model_context_window', self::DEFAULT_CONTEXT_WINDOW, $model),
        );
        $budget = max(4_096, $context_window - max(1_024, $max_completion_tokens) - self::SAFETY_TOKENS);
        $estimate = self::estimate_request_tokens($messages, $tools);

        return [
            'messages' => array_values($messages),
            'diagnostics' => [
                'estimated_input_tokens' => $estimate,
                'context_window' => $context_window,
                'input_budget' => $budget,
                'event_count' => count($events),
                'compacted_event_count' => $pruned,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function event_message(array $event): ?array {
        $type = (string) ($event['event_type'] ?? '');
        $payload = ArrayKey::as_map($event['payload'] ?? null);

        if (in_array($type, ['user', 'assistant', 'assistant_final'], true)) {
            return [
                'role' => 'user' === $type ? 'user' : 'assistant',
                'content' => (string) ($payload['content'] ?? ''),
            ];
        }

        if ('assistant_tool_calls' === $type) {
            return [
                'role' => 'assistant',
                'content' => (string) ($payload['content'] ?? ''),
                'tool_calls' => is_array($payload['tool_calls'] ?? null) ? $payload['tool_calls'] : [],
            ];
        }

        if ('tool_result' === $type) {
            return [
                'role' => 'tool',
                'tool_call_id' => (string) ($event['call_id'] ?? $payload['call_id'] ?? ''),
                'content' => (string) ($payload['content'] ?? '{}'),
            ];
        }

        if ('checkpoint' === $type || 'legacy_evidence' === $type) {
            return [
                'role' => 'system',
                'content' =>
                    'Historical session memory (revalidate mutable WordPress state): '
                        . (string) wp_json_encode($payload),
            ];
        }

        return null;
    }

    /**
     * @param array<int, array<array-key, mixed>> $messages
     * @param array<int, array<array-key, mixed>> $tools
     */
    public static function estimate_request_tokens(array $messages, array $tools = []): int {
        $encoded = wp_json_encode(['messages' => $messages, 'tools' => $tools]);

        return SessionEventRepository::estimate_tokens(is_string($encoded) ? $encoded : '');
    }

    /** @return array<int, array<string, mixed>> */
    private static function maps(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $maps = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $maps[] = ArrayKey::as_map($item);
        }

        return $maps;
    }
}
