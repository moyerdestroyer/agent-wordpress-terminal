<?php

/**
 * Short-lived progress state for an in-flight agent turn.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/** Shares backend phases with the polling admin UI while a REST request runs. */
final class ChatProgress {
    private const ACTIVE_TTL = 300;

    /** @return array<string, int|string> */
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
     * @param array{phase: string, label: string, detail?: string, completed?: int, total?: int} $update
     * @return array<string, int|string>
     */
    public function update(int $session_id, string $turn_id, array $update): array {
        return $this->write($session_id, $turn_id, [
            'state' => 'active',
            'phase' => sanitize_key($update['phase']),
            'label' => sanitize_text_field($update['label']),
            'detail' => sanitize_text_field($update['detail'] ?? ''),
            'completed' => max(0, $update['completed'] ?? 0),
            'total' => max(0, $update['total'] ?? 0),
        ]);
    }

    /** @return array<string, int|string> */
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

    /** @return array<string, int|string> */
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

    /** @return array<string, int|string> */
    public function read(int $session_id, string $turn_id): array {
        $progress = get_transient($this->key($session_id, $turn_id));

        if (!is_array($progress)) {
            return [
                'state' => 'pending',
                'phase' => 'starting',
                'label' => __('Sending request', 'agent-wordpress-terminal'),
                'detail' => '',
                'completed' => 0,
                'total' => 0,
                'sequence' => 0,
                'updated_at' => '',
            ];
        }

        /** @var array<string, int|string> $progress */
        return $progress;
    }

    /**
     * @param array{state: string, phase: string, label: string, detail: string, completed: int, total: int} $progress
     * @return array<string, int|string>
     */
    private function write(int $session_id, string $turn_id, array $progress): array {
        $previous = $this->read($session_id, $turn_id);
        $progress['sequence'] = (int) ($previous['sequence'] ?? 0) + 1;
        $progress['updated_at'] = current_time('mysql');
        set_transient($this->key($session_id, $turn_id), $progress, self::ACTIVE_TTL);

        return $progress;
    }

    private function key(int $session_id, string $turn_id): string {
        return 'awpt_chat_progress_' . md5(get_current_user_id() . ':' . $session_id . ':' . sanitize_key($turn_id));
    }
}
