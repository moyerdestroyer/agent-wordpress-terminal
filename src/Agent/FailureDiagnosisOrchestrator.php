<?php

/**
 * Triggers auto-diagnosis after failed tool calls.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\IncidentRecorder;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Records tool failures and runs diagnosis when needed.
 */
final class FailureDiagnosisOrchestrator {
    /**
     * @param array<int, array<string, mixed>> $tool_calls
     * @return array<string, mixed>|null
     */
    public function diagnose_first_failure(int $session_id, array $tool_calls): ?array {
        foreach ($tool_calls as $tool_call) {
            if ('failed' !== (string) ($tool_call['status'] ?? '')) {
                continue;
            }

            $incident_id = new IncidentRecorder()->from_tool_call($session_id, $tool_call);

            if (null === $incident_id) {
                continue;
            }

            $diagnosis = new DiagnosisRuntime()->run($session_id, $incident_id, false);

            return is_wp_error($diagnosis) ? null : $diagnosis;
        }

        return null;
    }
}
