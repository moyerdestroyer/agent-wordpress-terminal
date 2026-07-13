<?php

/**
 * Incident REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Agent\DiagnosisRuntime;
use AWPT\Database\IncidentRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\IncidentRecorder;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Records incidents; diagnosis runs only when auto_diagnose is true or via /diagnose.
 */
final class IncidentsController extends RestController {
    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(AWPT_REST_NAMESPACE, '/sessions/(?P<session_id>\d+)/incidents', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_incident'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'kind' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'source' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'attempted_action' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'action_id' => [
                        'type' => 'integer',
                    ],
                    'error_text' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                    'url' => [
                        'type' => 'string',
                        'sanitize_callback' => 'esc_url_raw',
                    ],
                    'auto_diagnose' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/sessions/(?P<session_id>\d+)/diagnose', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'diagnose'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'incident_id' => [
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_incident(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $session_id = (int) $request->get_param('session_id');

        if (!new SessionRepository()->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $incident_id = new IncidentRecorder()->from_client_error($session_id, [
            'kind' => (string) $request->get_param('kind'),
            'source' => (string) $request->get_param('source'),
            'attempted_action' => (string) $request->get_param('attempted_action'),
            'action_id' => (int) $request->get_param('action_id'),
            'error_text' => (string) $request->get_param('error_text'),
        ]);

        if (null === $incident_id) {
            return new \WP_Error(
                'awpt_incident_empty',
                __('Incident error text is required.', 'agent-wordpress-terminal'),
                [
                    'status' => 400,
                ],
            );
        }

        $response = [
            'incident_id' => $incident_id,
            'incident' => new IncidentRepository()->get($incident_id),
        ];

        if (rest_sanitize_boolean($request->get_param('auto_diagnose'))) {
            $diagnosis = new DiagnosisRuntime()->run($session_id, $incident_id);

            if (!is_wp_error($diagnosis)) {
                $response['diagnosis_response'] = $diagnosis;
            } else {
                $response['diagnosis_error'] = $diagnosis->get_error_message();
            }
        }

        return rest_ensure_response($response);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function diagnose(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $session_id = (int) $request->get_param('session_id');
        $incident_id = (int) $request->get_param('incident_id');

        if ($incident_id <= 0) {
            $latest = new IncidentRepository()->latest_open($session_id);
            $incident_id = (int) ($latest['id'] ?? 0);
        }

        if ($incident_id <= 0) {
            return new \WP_Error('awpt_incident_not_found', __('No open incident found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $result = new DiagnosisRuntime()->run($session_id, $incident_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }
}
