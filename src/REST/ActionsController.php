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
use AWPT\Support\ActionOperations;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Manages staged proposed actions.
 */
final class ActionsController extends RestController
{
    private ActionRepository $actions;
    private StagedPostPreview $preview;

    public function __construct(
        ?ActionRepository $actions = null,
        ?StagedPostPreview $preview = null,
    ) {
        $this->actions = $actions ?? new ActionRepository();
        $this->preview = $preview ?? new StagedPostPreview();
    }

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(AWPT_REST_NAMESPACE, '/actions/(?P<id>\d+)/preview', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'preview_action'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/actions/(?P<id>\d+)', [
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
     * Build a frontend preview URL for a staged content update.
     */
    public function preview_action(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $action = $this->actions->get_accessible_row(RequestParams::int($request, 'id'));

        if (null === $action) {
            return new \WP_Error(
                code: 'awpt_action_not_found',
                message: __('Action not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $payload = $this->actions->decode_payload($action);
        $operation = (string) ($payload['operation'] ?? '');

        if (!ActionOperations::is_previewable($operation)) {
            return new \WP_Error(
                code: 'awpt_preview_unsupported',
                message: __('Only post content actions can be previewed.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        if (ActionOperations::NEW_POST === $operation && (int) ($payload['post_id'] ?? 0) <= 0) {
            $prepared = $this->preview->prepare_new_post_payload($payload);

            if (is_wp_error($prepared)) {
                return $prepared;
            }

            $this->actions->update_payload((int) $action['id'], $prepared);
            $payload = $prepared;
        }

        $preview = $this->preview->preview_from_payload($payload);

        if (is_wp_error($preview)) {
            return $preview;
        }

        if (ActionOperations::CONTENT_UPDATE === $operation && array_key_exists('autosave_id', $preview)) {
            $payload['preview_autosave_id'] = (int) $preview['autosave_id'];
            $this->actions->update_payload((int) $action['id'], $payload);
        }

        return new \WP_REST_Response($preview, status: 200);
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
            if ('reject' === $operation) {
                $this->preview->discard_preview_resources($this->actions->decode_payload($action));
            }

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

        $result = new ApplyAction()->execute(['action_id' => $action_id]);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($this->actions->format_action($action_id), status: 200);
    }
}
