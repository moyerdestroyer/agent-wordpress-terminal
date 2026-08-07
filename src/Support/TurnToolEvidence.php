<?php

/**
 * In-request tool evidence for the active agent turn.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Accumulates tool calls during a turn before MessageRepository persists them.
 *
 * Pattern-first gates and discovery reuse need recommend/read evidence while
 * propose tools run mid-turn; DB rows are only written after the turn ends.
 */
final class TurnToolEvidence {
    /**
     * @var array<int, list<array{tool: string, input: mixed, output: mixed, status: string}>>
     */
    private static array $by_session = [];

    public static function reset(?int $session_id = null): void {
        if (null === $session_id) {
            self::$by_session = [];

            return;
        }

        unset(self::$by_session[$session_id]);
    }

    /**
     * @param array{tool?: string, input?: mixed, output?: mixed, status?: string} $call
     */
    public static function record(int $session_id, array $call): void {
        if ($session_id <= 0) {
            return;
        }

        $tool = (string) ($call['tool'] ?? '');
        if ('' === $tool) {
            return;
        }

        self::$by_session[$session_id][] = [
            'tool' => $tool,
            'input' => $call['input'] ?? null,
            'output' => $call['output'] ?? null,
            'status' => (string) ($call['status'] ?? 'success'),
        ];
    }

    /**
     * @return list<array{tool: string, input: mixed, output: mixed, status: string}>
     */
    public static function for_session(int $session_id): array {
        if ($session_id <= 0) {
            return [];
        }

        return self::$by_session[$session_id] ?? [];
    }
}
