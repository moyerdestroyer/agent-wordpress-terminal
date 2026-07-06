<?php

/**
 * Chat REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Agent\AgentRuntime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles chat messages for agent sessions.
 */
final class ChatController {
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
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Permission check.
     */
    public function can_manage(): bool {
        return current_user_can('manage_options');
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

        if ('' === trim($message)) {
            return new \WP_Error('awpt_empty_message', __('Message cannot be empty.', 'agent-wordpress-terminal'), [
                'status' => 400,
            ]);
        }

        $runtime = new AgentRuntime();
        $result = $runtime->handle_message($session_id, $message);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }
}
