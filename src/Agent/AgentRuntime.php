<?php

/**
 * Agent runtime.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\MessageRepository;
use AWPT\Database\SessionRepository;
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

        $now = current_time('mysql');

        $stored_message = $this->message_with_attachment_summary($message, $turn_context['attachments'] ?? []);
        $this->messages->store_message($session_id, 'user', $stored_message, $now);

        $response = $this->dispatch_message($session_id, $message, $turn_context);

        if (is_wp_error($response)) {
            return $response;
        }

        if ('clear' === ($response['command'] ?? '')) {
            $this->sessions->clear_transcript($session_id);
        } else {
            $stored = $this->messages->store_message($session_id, 'assistant', (string) $response['content'], $now);

            if (!$stored) {
                return new \WP_Error(
                    code: 'awpt_message_store_failed',
                    message: __('Could not store the assistant response.', 'agent-wordpress-terminal'),
                    data: ['status' => 500],
                );
            }

            $this->messages->store_tool_calls($session_id, $response['tool_calls'] ?? [], $now);
        }

        $session_update = ['updated_at' => $now];
        $session_formats = ['%s'];
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

            $lines[] = sprintf(
                'Attached image: %s (Media Library attachment #%d)',
                (string) ($attachment['url'] ?? ''),
                (int) ($attachment['id'] ?? 0),
            );
        }

        return implode("\n\n", array_filter([trim($message), implode("\n", $lines)]));
    }
}
