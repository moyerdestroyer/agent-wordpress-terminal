<?php

/**
 * Chat REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Agent\AgentRuntime;
use AWPT\Agent\ChatProgress;
use AWPT\Database\SessionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles chat messages for agent sessions.
 */
final class ChatController extends RestController {
    private AgentRuntime $runtime;

    public function __construct(?AgentRuntime $runtime = null) {
        $this->runtime = $runtime ?? new AgentRuntime();
    }

    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(AWPT_REST_NAMESPACE, '/sessions/(?P<session_id>\d+)/chat', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'send_message'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'message' => [
                        'required' => false,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                    'turn_id' => [
                        'required' => false,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'attachments' => [
                        'required' => false,
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
        ]);
        register_rest_route(AWPT_REST_NAMESPACE, '/sessions/(?P<session_id>\d+)/chat-progress', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'progress'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'turn_id' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Send a user message and get an agent response.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function send_message(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $session_id = (int) $request->get_param('session_id');
        $message = (string) $request->get_param('message');
        $attachments = $this->sanitize_attachments($request->get_param('attachments'));
        $turn_id = sanitize_key((string) $request->get_param('turn_id'));
        $turn_id = '' !== $turn_id ? $turn_id : (string) wp_generate_uuid4();

        if ('' === trim($message) && [] === $attachments) {
            return new \WP_Error('awpt_empty_message', __('Message cannot be empty.', 'agent-wordpress-terminal'), [
                'status' => 400,
            ]);
        }

        $progress = new ChatProgress();
        $progress->begin($session_id, $turn_id);
        $result = $this->runtime->handle_message($session_id, $message, [
            'turn_id' => $turn_id,
            'attachments' => $attachments,
        ]);

        if (is_wp_error($result)) {
            $progress->failed($session_id, $turn_id, $result->get_error_message());
            return $result;
        }

        $progress->complete($session_id, $turn_id);
        return new \WP_REST_Response($result, 200);
    }

    public function progress(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $session_id = (int) $request->get_param('session_id');

        if (!new SessionRepository()->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        return new \WP_REST_Response(
            new ChatProgress()->read($session_id, sanitize_key((string) $request->get_param('turn_id'))),
            200,
        );
    }

    /** @return list<array{id: int, url: string, filename: string, mime_type: string}> */
    private function sanitize_attachments(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $attachments = [];

        foreach (array_slice($value, 0, 10) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = absint(is_scalar($item['id'] ?? null) ? $item['id'] : 0);
            $url = esc_url_raw(is_scalar($item['url'] ?? null) ? (string) $item['url'] : '');

            if ($id <= 0 || '' === $url) {
                continue;
            }

            $attachments[] = [
                'id' => $id,
                'url' => $url,
                'filename' => sanitize_file_name((string) ($item['filename'] ?? '')),
                'mime_type' => sanitize_mime_type((string) ($item['mime_type'] ?? '')),
            ];
        }

        return $attachments;
    }
}
