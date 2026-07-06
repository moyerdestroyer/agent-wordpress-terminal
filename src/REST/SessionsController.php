<?php

/**
 * Sessions REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Database\SessionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * CRUD endpoints for agent sessions.
 */
final class SessionsController extends RestController
{
    private SessionRepository $sessions;

    public function __construct(?SessionRepository $sessions = null)
    {
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(AWPT_REST_NAMESPACE, '/sessions', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'list_sessions'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_session'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/sessions/(?P<id>\d+)', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_session'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'messages_limit' => [
                        'type' => 'integer',
                        'default' => 50,
                    ],
                    'include_tool_outputs' => [
                        'type' => 'integer',
                        'default' => 0,
                    ],
                ],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_session'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'title' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_session'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
    }

    /**
     * List sessions visible to admins.
     */
    public function list_sessions(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->sessions->list_summaries(), status: 200);
    }

    /**
     * Create a new session.
     */
    public function create_session(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $title = sanitize_text_field(RequestParams::string($request, 'title'));

        if ('' === $title) {
            $title = __('New session', 'agent-wordpress-terminal');
        }

        $created = $this->sessions->create($title);

        if ([] === $created) {
            return new \WP_Error(
                code: 'awpt_session_create_failed',
                message: __('Could not create session.', 'agent-wordpress-terminal'),
                data: ['status' => 500],
            );
        }

        return new \WP_REST_Response($created, status: 201);
    }

    /**
     * Get a session with messages and context.
     */
    public function get_session(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $session_id = RequestParams::int($request, 'id');
        $messages_limit = RequestParams::int($request, 'messages_limit');
        $messages_limit = $messages_limit > 0 ? $messages_limit : 50;
        $messages_limit = max(1, min(200, $messages_limit));
        $include_tool_outputs = 1 === RequestParams::int($request, 'include_tool_outputs');
        $session = $this->sessions->find_detail($session_id, $messages_limit, $include_tool_outputs);

        if (null === $session) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        return new \WP_REST_Response($session, status: 200);
    }

    /**
     * Update a session.
     */
    public function update_session(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $session_id = RequestParams::int($request, 'id');
        $title = sanitize_text_field(RequestParams::string($request, 'title'));

        if ('' === $title) {
            return new \WP_Error(
                code: 'awpt_session_title_required',
                message: __('Session title is required.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $summary = $this->sessions->update_title($session_id, $title);

        return new \WP_REST_Response($summary, status: 200);
    }

    /**
     * Delete a session and its associated records.
     */
    public function delete_session(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $session_id = RequestParams::int($request, 'id');

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $this->sessions->delete($session_id);

        return new \WP_REST_Response(['deleted' => true, 'id' => $session_id], status: 200);
    }
}
