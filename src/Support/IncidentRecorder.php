<?php

/**
 * Records incidents from failures across AWPT.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Database\IncidentRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates incident rows from tool, apply, preview, and client failures.
 */
final class IncidentRecorder {
    private IncidentRepository $incidents;

    public function __construct(?IncidentRepository $incidents = null) {
        $this->incidents = $incidents ?? new IncidentRepository();
    }

    /**
     * @param array<string, mixed> $tool_call
     */
    public function from_tool_call(int $session_id, array $tool_call): ?int {
        if ('failed' !== (string) ($tool_call['status'] ?? '')) {
            return null;
        }

        $output = is_array($tool_call['output'] ?? null) ? $tool_call['output'] : [];
        $error_text = (string) ($output['error'] ?? '');
        $error_code = (string) ($output['error_code'] ?? '');

        if ('' === $error_text) {
            return null;
        }

        if ('' !== $error_code) {
            $error_text = $error_code . ': ' . $error_text;
        }

        return $this->incidents->create($session_id, [
            'kind' => 'tool_failure',
            'source' => (string) ($tool_call['tool'] ?? ''),
            'attempted_action' => (string) ($tool_call['tool'] ?? ''),
            'error_text' => $error_text,
        ]);
    }

    public function from_apply_failure(
        int $session_id,
        int $action_id,
        string $error_text,
        string $attempted_action = 'apply',
    ): ?int {
        if ('' === trim($error_text)) {
            return null;
        }

        return $this->incidents->create($session_id, [
            'kind' => 'apply_failure',
            'source' => 'actions',
            'attempted_action' => $attempted_action,
            'action_id' => $action_id,
            'error_text' => $error_text,
        ]);
    }

    public function from_preview_failure(int $session_id, int $action_id, string $error_text): ?int {
        return $this->from_apply_failure($session_id, $action_id, $error_text, 'preview');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function from_client_error(int $session_id, array $payload): ?int {
        $error_text = sanitize_textarea_field((string) ($payload['error_text'] ?? ''));

        if ('' === $error_text) {
            return null;
        }

        return $this->incidents->create($session_id, [
            'kind' => sanitize_key((string) ($payload['kind'] ?? 'js')),
            'source' => sanitize_text_field((string) ($payload['source'] ?? 'awpt-admin')),
            'attempted_action' => sanitize_key((string) ($payload['attempted_action'] ?? '')),
            'action_id' => absint($payload['action_id'] ?? 0) > 0 ? absint($payload['action_id']) : null,
            'error_text' => $error_text,
        ]);
    }
}
