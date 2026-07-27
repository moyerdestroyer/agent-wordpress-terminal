<?php

/**
 * Short-lived progress state for an in-flight agent turn.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ProviderCallRepository;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/** Shares backend phases with the polling admin UI while a REST request runs. */
final class ChatProgress {
    private const ACTIVE_TTL = 300;

    /** @return array<string, mixed> */
    public function begin(int $session_id, string $turn_id): array {
        return $this->write($session_id, $turn_id, [
            'state' => 'active',
            'phase' => 'starting',
            'label' => __('Starting request', 'agent-wordpress-terminal'),
            'detail' => __('Preparing session context…', 'agent-wordpress-terminal'),
            'completed' => 0,
            'total' => 0,
        ]);
    }

    /**
     * @param array{phase: string, label: string, detail?: string, completed?: int, total?: int, diagnostics?: array<string, mixed>} $update
     * @return array<string, mixed>
     */
    public function update(int $session_id, string $turn_id, array $update): array {
        return $this->write($session_id, $turn_id, [
            'state' => 'active',
            'phase' => sanitize_key($update['phase']),
            'label' => sanitize_text_field($update['label']),
            'detail' => sanitize_text_field($update['detail'] ?? ''),
            'completed' => max(0, $update['completed'] ?? 0),
            'total' => max(0, $update['total'] ?? 0),
            'diagnostics' => $this->sanitize_diagnostics($update['diagnostics'] ?? []),
        ]);
    }

    /** @return array<string, mixed> */
    public function complete(int $session_id, string $turn_id): array {
        return $this->write($session_id, $turn_id, [
            'state' => 'complete',
            'phase' => 'complete',
            'label' => __('Response ready', 'agent-wordpress-terminal'),
            'detail' => '',
            'completed' => 1,
            'total' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    public function failed(int $session_id, string $turn_id, string $detail): array {
        return $this->write($session_id, $turn_id, [
            'state' => 'failed',
            'phase' => 'failed',
            'label' => __('Request failed', 'agent-wordpress-terminal'),
            'detail' => sanitize_text_field($detail),
            'completed' => 0,
            'total' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    public function read(int $session_id, string $turn_id): array {
        $progress = $this->read_stored($session_id, $turn_id);

        if (null === $progress) {
            return [
                'state' => 'pending',
                'phase' => 'starting',
                'label' => __('Sending request', 'agent-wordpress-terminal'),
                'detail' => '',
                'completed' => 0,
                'total' => 0,
                'sequence' => 0,
                'updated_at' => '',
                'diagnostics' => [],
            ];
        }

        $latest_call = new ProviderCallRepository()->latest_for_turn($session_id, sanitize_key($turn_id));

        if (null !== $latest_call) {
            $diagnostics = is_array($progress['diagnostics'] ?? null) ? $progress['diagnostics'] : [];
            $diagnostics['last_completed_call'] = $latest_call;
            $progress['diagnostics'] = $diagnostics;
        }

        return $progress;
    }

    /**
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    private function write(int $session_id, string $turn_id, array $progress): array {
        $previous = $this->read_stored($session_id, $turn_id) ?? [];

        if (
            !array_key_exists('diagnostics', $progress)
            && is_array($previous['diagnostics'] ?? null)
            && [] !== $previous['diagnostics']
        ) {
            $progress['diagnostics'] = $previous['diagnostics'];
        }

        $progress['sequence'] = (int) ($previous['sequence'] ?? 0) + 1;
        $progress['updated_at'] = current_time('mysql');
        set_transient($this->key($session_id, $turn_id), $progress, self::ACTIVE_TTL);

        return $progress;
    }

    /** @return array<string, mixed>|null */
    private function read_stored(int $session_id, string $turn_id): ?array {
        $progress = get_transient($this->key($session_id, $turn_id));

        return is_array($progress) ? ArrayKey::string_map($progress) : null;
    }

    private function key(int $session_id, string $turn_id): string {
        return 'awpt_chat_progress_' . md5(get_current_user_id() . ':' . $session_id . ':' . sanitize_key($turn_id));
    }

    /** @param array<string, mixed> $diagnostics @return array<string, int|string|bool> */
    private function sanitize_diagnostics(array $diagnostics): array {
        $clean = [];

        foreach (array_keys($diagnostics) as $key) {
            $value = $this->sanitize_diagnostic_value($diagnostics[$key] ?? null);

            if (null === $value) {
                continue;
            }

            $clean[sanitize_key($key)] = $value;
        }

        return $clean;
    }

    private function sanitize_diagnostic_value(mixed $value): int|string|bool|null {
        if (is_bool($value) || is_int($value)) {
            return $value;
        }

        return is_scalar($value) ? sanitize_text_field(wp_strip_all_tags((string) $value)) : null;
    }
}
