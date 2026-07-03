<?php

/**
 * Actions REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Abilities\ApplyAction;
use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Manages staged proposed actions.
 */
final class ActionsController extends RestController
{
    private ActionRepository $actions;
    private SessionRepository $sessions;

    public function __construct(?ActionRepository $actions = null, ?SessionRepository $sessions = null)
    {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(AWPT_REST_NAMESPACE, '/sessions/(?P<session_id>\d+)/actions', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_action'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'title' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'description' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                    'payload' => [
                        'required' => true,
                        'type' => 'object',
                    ],
                ],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/actions/(?P<id>\d+)', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_action'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_action'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'operation' => [
                        'required' => true,
                        'type' => 'string',
                        'enum' => ['approve', 'reject', 'apply'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Create a staged proposed action.
     */
    public function create_action(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $session_id = RequestParams::int($request, 'session_id');

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $raw_payload = $request->get_param('payload');

        if (!is_array($raw_payload)) {
            return new \WP_Error(
                code: 'awpt_invalid_action_payload',
                message: __('Action payload must be an object.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $payload = $raw_payload;

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field(RequestParams::string($request, 'title')),
            description: sanitize_textarea_field(RequestParams::string($request, 'description')),
            payload: $payload,
        );

        if (null === $action_id) {
            return new \WP_Error(
                code: 'awpt_action_create_failed',
                message: __('Could not create proposed action.', 'agent-wordpress-terminal'),
                data: ['status' => 500],
            );
        }

        return new \WP_REST_Response($this->actions->format_action($action_id), status: 201);
    }

    /**
     * Get a proposed action.
     */
    public function get_action(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $action = $this->actions->format_action(RequestParams::int($request, 'id'));

        if (null === $action) {
            return new \WP_Error(
                code: 'awpt_action_not_found',
                message: __('Action not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        return new \WP_REST_Response($action, status: 200);
    }

    /**
     * Approve, reject, or apply an action.
     */
    public function update_action(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $action_id = RequestParams::int($request, 'id');
        $operation = sanitize_key(RequestParams::string($request, 'operation'));
        $action = $this->actions->get_accessible_row($action_id);

        if (null === $action) {
            return new \WP_Error(
                code: 'awpt_action_not_found',
                message: __('Action not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        if ('reject' === $operation || 'approve' === $operation) {
            $this->actions->update_status($action_id, 'reject' === $operation ? 'rejected' : 'approved');

            return new \WP_REST_Response($this->actions->format_action($action_id), status: 200);
        }

        if ('rejected' === $action['status']) {
            return new \WP_Error(
                code: 'awpt_action_rejected',
                message: __('Rejected actions cannot be applied.', 'agent-wordpress-terminal'),
                data: ['status' => 409],
            );
        }

        if ('applied' === $action['status']) {
            return new \WP_REST_Response($this->actions->format_action($action_id), status: 200);
        }

        $this->actions->update_status($action_id, 'approved');

        $result = (new ApplyAction())->execute(['action_id' => $action_id]);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($this->actions->format_action($action_id), status: 200);
    }
}
