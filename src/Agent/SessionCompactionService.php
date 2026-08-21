<?php

/**
 * Token-aware, tools-off checkpoint compaction for long-running sessions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ProviderCallRepository;
use AWPT\Database\SessionEventRepository;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class SessionCompactionService {
    private const DEFAULT_WINDOW = 128_000;
    private const SAFETY = 8_192;
    private const TAIL_MESSAGES = 12;

    /**
     * @param array{session_id?: int, turn_id?: string, messages?: array<int, array<string, mixed>>, tools?: array<int, array<string, mixed>>, max_completion_tokens?: int} $context
     * @return array{messages: list<array<string, mixed>>, compacted: bool, estimated_input_tokens: int, checkpoint_id: int}
     */
    public function compact_if_needed(ProviderInterface $provider, array $context): array {
        $session_id = (int) ($context['session_id'] ?? 0);
        $turn_id = $context['turn_id'] ?? '';
        $messages = self::maps($context['messages'] ?? null);
        $tools = self::maps($context['tools'] ?? null);
        $max_completion_tokens = (int) ($context['max_completion_tokens'] ?? 8_192);
        $estimate = SessionContextProjector::estimate_request_tokens($messages, $tools);
        $window = max(16_384, (int) apply_filters('awpt_model_context_window', self::DEFAULT_WINDOW, ''));
        $budget = max(4_096, $window - max(1_024, $max_completion_tokens) - self::SAFETY);

        if ($estimate <= $budget || count($messages) <= (self::TAIL_MESSAGES + 1)) {
            return [
                'messages' => $messages,
                'compacted' => false,
                'estimated_input_tokens' => $estimate,
                'checkpoint_id' => 0,
            ];
        }

        $system = $messages[0];
        $tail = array_slice($messages, -self::TAIL_MESSAGES);
        $head = array_slice($messages, 1, max(0, count($messages) - self::TAIL_MESSAGES - 1));
        $serialized = $this->bounded_head($head);
        $started_at = microtime(true);
        $result = $provider->complete(
            [
                [
                    'role' => 'system',
                    'content' => 'Create a factual session checkpoint from the supplied historical events. Return one JSON object only with keys goal, constraints, decisions, completed, unresolved, references, evidence, and freshness. Preserve exact WordPress IDs, paths, fingerprints, action IDs, tool failures, and user constraints. Never claim mutable site state is still current.',
                ],
                ['role' => 'user', 'content' => $serialized],
            ],
            [],
            [
                'session_id' => $session_id,
                'turn_id' => $turn_id,
                'tool_round' => 0,
                'log_phase' => 'session_compaction',
                'max_completion_tokens' => 4_096,
                'timeout' => 120,
                'tool_choice' => 'none',
            ],
        );
        new ProviderCallRepository()->store($session_id, [
            'provider' => $provider->get_name(),
            'turn_id' => $turn_id,
            'tool_round' => 0,
            'budget' => 4_096,
            'duration_ms' => (int) round((microtime(true) - $started_at) * 1_000),
            'result' => $result,
            'outcome' => is_wp_error($result) ? 'error' : 'success',
        ]);
        $checkpoint = is_array($result) ? $this->decode_checkpoint((string) ($result['content'] ?? '')) : null;

        if (null === $checkpoint) {
            // Do not replace known-good context with an invented or malformed
            // checkpoint. The provider can return an honest capacity error.
            return [
                'messages' => $messages,
                'compacted' => false,
                'estimated_input_tokens' => $estimate,
                'checkpoint_id' => 0,
            ];
        }

        $events = new SessionEventRepository();
        $covered = $events->latest_id($session_id);
        $checkpoint_id = $events->append($session_id, [
            'turn_id' => $turn_id,
            'ordinal' => 9_000,
            'event_type' => 'checkpoint',
            'payload' => $checkpoint,
            'covers_through_event_id' => $covered,
        ]);
        $checkpoint_message = [
            'role' => 'system',
            'content' =>
                'Historical session checkpoint; revalidate mutable WordPress state: '
                    . (string) wp_json_encode($checkpoint),
        ];
        $compacted = [$system, $checkpoint_message, ...$tail];

        return [
            'messages' => $compacted,
            'compacted' => true,
            'estimated_input_tokens' => SessionContextProjector::estimate_request_tokens($compacted, $tools),
            'checkpoint_id' => $checkpoint_id,
        ];
    }

    /** @param list<array<string, mixed>> $head */
    private function bounded_head(array $head): string {
        foreach ($head as $index => $message) {
            if (!(is_string($message['content'] ?? null) && strlen($message['content']) > 4_000)) {
                continue;
            }

            $head[$index]['content'] = mb_substr($message['content'], 0, 4_000) . "\n[older body truncated]";
        }

        $encoded = wp_json_encode($head);
        $encoded = is_string($encoded) ? $encoded : '[]';

        return strlen($encoded) > 300_000 ? substr($encoded, -300_000) : $encoded;
    }

    /** @return array<string, mixed>|null */
    private function decode_checkpoint(string $content): ?array {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return null;
        }

        foreach ([
            'goal',
            'constraints',
            'decisions',
            'completed',
            'unresolved',
            'references',
            'evidence',
            'freshness',
        ] as $key) {
            if (!array_key_exists($key, $decoded)) {
                return null;
            }
        }

        return ArrayKey::as_map($decoded);
    }

    /** @return list<array<string, mixed>> */
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
