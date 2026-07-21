<?php

/**
 * Opt-in diagnosis runtime for recorded incidents.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\IncidentRepository;
use AWPT\Database\MessageRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ArrayKey;
use AWPT\Support\Diagnostics\DiagnosisInstructions;
use AWPT\Support\IncidentRecorder;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Records tool failures and runs diagnosis when explicitly requested.
 */
final class DiagnosisRuntime {
    private IncidentRepository $incidents;
    private SessionRepository $sessions;
    private MessageRepository $messages;

    public function __construct(
        ?IncidentRepository $incidents = null,
        ?SessionRepository $sessions = null,
        ?MessageRepository $messages = null,
    ) {
        $this->incidents = $incidents ?? new IncidentRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->messages = $messages ?? new MessageRepository();
    }

    /**
     * Record the first failed tool call as an open incident (no nested agent turn).
     *
     * @param array<int, array<string, mixed>> $tool_calls
     */
    public function record_first_failure(int $session_id, array $tool_calls): ?int {
        foreach ($tool_calls as $tool_call) {
            if ('failed' !== (string) ($tool_call['status'] ?? '')) {
                continue;
            }

            $incident_id = new IncidentRecorder()->from_tool_call($session_id, $tool_call);

            if (null !== $incident_id) {
                return $incident_id;
            }
        }

        return null;
    }

    /**
     * Run diagnosis and persist incident/assistant messages to the transcript.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function run(int $session_id, int $incident_id): array|\WP_Error {
        return $this->execute($session_id, $incident_id, true);
    }

    /**
     * Run diagnosis without writing messages (nested/tool-loop use).
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function run_without_transcript(int $session_id, int $incident_id): array|\WP_Error {
        return $this->execute($session_id, $incident_id, false);
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function execute(int $session_id, int $incident_id, bool $persist_messages): array|\WP_Error {
        if (!$this->sessions->exists($session_id) || !current_user_can('manage_options')) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'));
        }

        $incident_row = $this->incidents->get($incident_id);
        $incident = null !== $incident_row ? ArrayKey::string_map($incident_row) : null;

        if (null === $incident || (int) ($incident['session_id'] ?? 0) !== $session_id) {
            return new \WP_Error('awpt_incident_not_found', __('Incident not found.', 'agent-wordpress-terminal'));
        }

        if ('resolved' === ($incident['status'] ?? '')) {
            return new \WP_Error('awpt_incident_resolved', __(
                'Incident is already resolved.',
                'agent-wordpress-terminal',
            ));
        }

        $diagnosis_tool = new ToolExecutor()->execute('awpt/diagnose-error', [
            'session_id' => $session_id,
            'error_text' => (string) ($incident['error_text'] ?? ''),
            'source' => (string) ($incident['source'] ?? ''),
            'attempted_action' => (string) ($incident['attempted_action'] ?? ''),
            'action_id' => (int) ($incident['action_id'] ?? 0) > 0 ? (int) $incident['action_id'] : null,
            'kind' => (string) ($incident['kind'] ?? ''),
        ]);

        $diagnosis = is_wp_error($diagnosis_tool) ? [] : ArrayKey::as_map($diagnosis_tool);
        $provider = new ProviderFactory()->make();
        $messages = new ProviderMessageBuilder()->build($session_id);
        $messages[] = [
            'role' => 'user',
            'content' => $this->incident_prompt($incident, $diagnosis),
        ];

        $tool_registry = new ToolRegistry();
        $result = $provider->complete($messages, $tool_registry->get_chat_completion_tools());

        if (is_wp_error($result)) {
            return $result;
        }

        $loop_result = new ProviderRuntime()->run_tool_loop($session_id, $provider, $messages, $result, [
            'tool_registry' => $tool_registry,
        ]);

        $now = current_time('mysql');
        $content = $loop_result['content'];

        if ('' === trim($content)) {
            $content = new ToolResultFormatter()->format_for_transcript($loop_result['tool_calls'], '');
        }

        if ($persist_messages) {
            $this->messages->store_message($session_id, 'incident', $this->incident_note($incident), $now);
            $this->messages->store_message($session_id, 'assistant', $content, $now);
            $this->messages->store_tool_calls($session_id, $loop_result['tool_calls'], $now);
        }

        $this->incidents->mark_diagnosed($incident_id, $diagnosis);

        return [
            'incident_id' => $incident_id,
            'incident' => $incident,
            'diagnosis' => $diagnosis,
            'content' => $content,
            'tool_calls' => $loop_result['tool_calls'],
            'actions' => $loop_result['actions'],
            'provider' => $provider->get_name(),
            'model' => $loop_result['model'],
        ];
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $diagnosis
     */
    private function incident_prompt(array $incident, array $diagnosis): string {
        $lines = [
            '[AWPT incident] A failure occurred and needs diagnosis.',
            sprintf('Kind: %s', (string) ($incident['kind'] ?? 'unknown')),
            sprintf('Source: %s', (string) ($incident['source'] ?? '')),
            sprintf('Attempted action: %s', (string) ($incident['attempted_action'] ?? '')),
            sprintf("Error:\n%s", (string) ($incident['error_text'] ?? '')),
        ];

        if ([] !== $diagnosis) {
            $encoded = wp_json_encode($diagnosis);
            $lines[] = 'Structured diagnosis seed: ' . (is_string($encoded) ? $encoded : '{}');
        }

        $lines[] = DiagnosisInstructions::incident_response_guidance();

        return implode("\n\n", $lines);
    }

    /**
     * @param array<string, mixed> $incident
     */
    private function incident_note(array $incident): string {
        return sprintf(
            /* translators: 1: incident kind, 2: source */
            __('Incident recorded (%1$s via %2$s). Diagnosis started.', 'agent-wordpress-terminal'),
            (string) ($incident['kind'] ?? 'error'),
            (string) ($incident['source'] ?? 'unknown'),
        );
    }
}
