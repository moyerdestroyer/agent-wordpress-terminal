<?php

/**
 * Agent runtime.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ImproveWorkflowRepository;
use AWPT\Database\MessageRepository;
use AWPT\Database\SessionEventRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ArrayKey;
use AWPT\Support\ImprovePagePrompt;
use AWPT\Support\SessionTitleSuggester;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Receives user messages and returns agent responses.
 */
final class AgentRuntime {
    private SessionRepository $sessions;
    private MessageRepository $messages;

    public function __construct(?SessionRepository $sessions = null, ?MessageRepository $messages = null) {
        $this->sessions = $sessions ?? new SessionRepository();
        $this->messages = $messages ?? new MessageRepository();
    }

    /**
     * Handle a slash command or natural language message.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function handle_message(int $session_id, string $message, array $turn_context = []): array|\WP_Error {
        if (!$this->sessions->exists($session_id) || !current_user_can(capability: 'manage_options')) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $turn_id = sanitize_key((string) ($turn_context['turn_id'] ?? ''));
        $turn_id = '' !== $turn_id ? $turn_id : sanitize_key((string) wp_generate_uuid4());
        $turn_context['turn_id'] = $turn_id;
        $lock = new SessionTurnLock();

        if (!$lock->acquire($session_id, $turn_id)) {
            return new \WP_Error(
                'awpt_session_busy',
                __('This session is already processing another turn.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        try {
            return $this->handle_unlocked($session_id, $message, $turn_context);
        } finally {
            $lock->release($session_id);
        }
    }

    /** @return array<string, mixed>|\WP_Error */
    private function handle_unlocked(int $session_id, string $message, array $turn_context): array|\WP_Error {
        $started_at = current_time('mysql');

        // Expand plan/improve slash aliases before storage so TurnProfile sees the
        // evaluate marker (or redesign brief) and the transcript matches the wire message.
        $expanded = ImprovePagePrompt::expand_slash_command($message);
        $wire_message = null !== $expanded ? $expanded['message'] : $message;

        if (ImprovePagePrompt::is_evaluate_message($wire_message)) {
            $wire_message = ImprovePagePrompt::refresh_evaluate_message($wire_message);
        }
        $workflow_input = is_array($turn_context['workflow'] ?? null) ? $turn_context['workflow'] : [];
        $workflow_repository = new ImproveWorkflowRepository();
        $workflow = null;

        if (ImprovePagePrompt::is_evaluate_message($wire_message)) {
            $summary = $this->sessions->get_summary($session_id);
            $workflow = $workflow_repository->begin_evaluate(
                $session_id,
                (int) ($turn_context['focus_post_id'] ?? $summary['focus_post_id'] ?? 0),
                sanitize_key((string) ($turn_context['turn_id'] ?? '')),
            );
        } elseif (ImprovePagePrompt::is_act_message($wire_message)) {
            $active = $workflow_repository->get($session_id);
            $workflow_id = sanitize_text_field((string) ($workflow_input['id'] ?? $active['id'] ?? ''));
            $summary = $this->sessions->get_summary($session_id);
            $workflow = $workflow_repository->begin_act(
                $session_id,
                $workflow_id,
                (int) ($turn_context['focus_post_id'] ?? $summary['focus_post_id'] ?? 0),
                sanitize_key((string) ($turn_context['turn_id'] ?? '')),
            );
            if (is_wp_error($workflow)) {
                return $workflow;
            }
            $unit = ImprovePagePrompt::current_unit($workflow);
            $plan = (string) ($workflow['plan'] ?? '');
            $wire_message = null !== $unit
                ? ImprovePagePrompt::act_message_for_unit($unit, $plan)
                : ImprovePagePrompt::act_message($plan);
        }

        $stored_message = $this->message_with_attachment_summary($wire_message, $turn_context['attachments'] ?? []);
        $events = new SessionEventRepository();
        $events->import_legacy_if_needed($session_id);
        $this->messages->store_message($session_id, 'user', $stored_message, $started_at);
        $events->append($session_id, [
            'turn_id' => (string) $turn_context['turn_id'],
            'ordinal' => 0,
            'event_type' => 'user',
            'payload' => ['content' => $stored_message],
        ]);

        $response = $this->dispatch_message($session_id, $wire_message, $turn_context);

        if (is_wp_error($response)) {
            if (is_array($workflow)) {
                $workflow_repository->fail(
                    $session_id,
                    (string) $response->get_error_code(),
                    $response->get_error_message(),
                );
            }
            return $response;
        }

        if (ImprovePagePrompt::is_evaluate_message($wire_message)) {
            $outcome = is_array($response['turn_outcome'] ?? null) ? $response['turn_outcome'] : [];
            $workflow = 'failed' === (string) ($outcome['status'] ?? '')
                ? $workflow_repository->fail(
                    $session_id,
                    (string) ($outcome['error_code'] ?? 'awpt_improve_evaluate_failed'),
                    (string) ($outcome['message'] ?? __('Page evaluation failed.', 'agent-wordpress-terminal')),
                )
                : $workflow_repository->plan_ready(
                    $session_id,
                    (string) ($response['content'] ?? ''),
                    ArrayKey::list_of_maps($response['tool_calls'] ?? []),
                );
        } elseif (ImprovePagePrompt::is_act_message($wire_message)) {
            $action_ids = [];
            foreach (is_array($response['actions'] ?? null) ? $response['actions'] : [] as $action) {
                if (!(is_array($action) && (int) ($action['id'] ?? 0) > 0)) {
                    continue;
                }

                $action_ids[] = (int) $action['id'];
            }
            $workflow = $workflow_repository->finish_act(
                $session_id,
                $action_ids,
                ArrayKey::as_map($response['turn_outcome'] ?? null),
            );
        }

        if (is_array($workflow)) {
            $response['improve_workflow'] = $workflow;

            if ('failed' === (string) ($workflow['state'] ?? '')) {
                $response['turn_outcome'] = [
                    'status' => 'failed',
                    'error_code' => sanitize_key((string) ($workflow['error_code'] ?? 'awpt_improve_failed')),
                    'message' => (string) (
                        $workflow['error_message'] ?? __('Improve workflow failed.', 'agent-wordpress-terminal')
                    ),
                ];
            }
        }

        if ('clear' === ($response['command'] ?? '')) {
            $this->sessions->clear_transcript($session_id);
        } else {
            $completed_at = current_time('mysql');
            $stored = $this->messages->store_message(
                $session_id,
                'assistant',
                (string) $response['content'],
                $completed_at,
            );

            if (!$stored) {
                return new \WP_Error(
                    code: 'awpt_message_store_failed',
                    message: __('Could not store the assistant response.', 'agent-wordpress-terminal'),
                    data: ['status' => 500],
                );
            }

            $tool_calls = is_array($response['tool_calls'] ?? null) ? array_values($response['tool_calls']) : [];
            $this->messages->store_tool_calls($session_id, $tool_calls, $completed_at);
            $events->append($session_id, [
                'turn_id' => (string) $turn_context['turn_id'],
                'ordinal' => 10_000,
                'event_type' => 'assistant_final',
                'payload' => ['content' => (string) $response['content']],
            ]);
        }

        // A provider turn may run for minutes. Sort and audit sessions by when
        // the outcome was actually recorded, not when the user started it.
        $session_update = ['updated_at' => current_time('mysql')];
        $session_formats = ['%s'];
        $outcome = is_array($response['turn_outcome'] ?? null) ? $response['turn_outcome'] : [];

        if ([] !== $outcome) {
            $session_update['last_turn_id'] = sanitize_key((string) ($turn_context['turn_id'] ?? ''));
            $session_update['last_outcome_json'] = (string) wp_json_encode($outcome);
            $session_formats[] = '%s';
            $session_formats[] = '%s';
        }
        $suggested_title = new SessionTitleSuggester()->suggest(
            $stored_message,
            $this->sessions->get_summary($session_id),
        );

        if (null !== $suggested_title) {
            $session_update['title'] = $suggested_title;
            $session_formats[] = '%s';
            $response['session_title'] = $suggested_title;
        }

        if (array_key_exists('provider', $response)) {
            $session_update['provider'] = (string) $response['provider'];
            $session_update['model'] = (string) ($response['model'] ?? '');
            $session_formats[] = '%s';
            $session_formats[] = '%s';
        }

        if (array_key_exists('focus_post_id', $response) && (int) $response['focus_post_id'] > 0) {
            $session_update['focus_post_id'] = (int) $response['focus_post_id'];
            $session_formats[] = '%d';
        }

        $this->sessions->update_fields($session_id, $session_update, $session_formats);

        return $response;
    }

    /**
     * Route message to slash commands or the provider.
     *
     * @return array<string, mixed>|\WP_Error
     */
    private function dispatch_message(int $session_id, string $message, array $turn_context): array|\WP_Error {
        $trimmed = trim($message);

        if (str_starts_with($trimmed, '/')) {
            return new SlashCommandRouter()->dispatch($trimmed);
        }

        return $this->provider_response($session_id, $turn_context);
    }

    /**
     * @return array<string, mixed>
     */
    private function provider_response(int $session_id, array $turn_context): array {
        return new ProviderRuntime()->respond($session_id, $turn_context);
    }

    private function message_with_attachment_summary(string $message, mixed $attachments): string {
        if (!is_array($attachments)) {
            return $message;
        }

        $lines = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $mime = (string) ($attachment['mime_type'] ?? '');
            $kind = str_starts_with($mime, 'image/') ? 'image' : 'document';
            $lines[] = sprintf(
                'Attached %s: %s (Media Library attachment #%d, MIME %s)',
                $kind,
                (string) ($attachment['url'] ?? ''),
                (int) ($attachment['id'] ?? 0),
                '' !== $mime ? $mime : 'unknown',
            );
        }

        return implode("\n\n", array_filter([trim($message), implode("\n", $lines)]));
    }
}
