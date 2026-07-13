<?php

/**
 * Tools REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\MCP\Adapter;
use AWPT\Support\ToolPreferences;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Exposes registered abilities, MCP tools, and enable/disable preferences.
 */
final class ToolsController extends RestController {
    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(AWPT_REST_NAMESPACE, '/tools', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'list_tools'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/tools/awpt', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'list_awpt_tools'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/tools/preferences', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_preferences'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_preferences'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'disabled' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'required' => false,
                    ],
                    'name' => [
                        'type' => 'string',
                        'required' => false,
                    ],
                    'enabled' => [
                        'type' => 'boolean',
                        'required' => false,
                    ],
                ],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/mcp/status', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'mcp_status'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/mcp/tools', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'mcp_tools'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/mcp/tools/(?P<name>.+)/execute', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'execute_mcp_tool'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'input' => [
                        'type' => 'object',
                        'default' => [],
                    ],
                ],
            ],
        ]);
    }

    public function list_tools(): \WP_REST_Response {
        return new \WP_REST_Response(new ToolsPayloadBuilder()->full(), 200);
    }

    public function list_awpt_tools(): \WP_REST_Response {
        return new \WP_REST_Response(new ToolsPayloadBuilder()->awpt_only(), 200);
    }

    public function get_preferences(): \WP_REST_Response {
        $prefs = new ToolPreferences();

        return new \WP_REST_Response([
            'disabled' => $prefs->disabled_names(),
            'never_auto' => ToolPreferences::NEVER_AUTO_EXECUTE,
        ], 200);
    }

    /**
     * @param \WP_REST_Request $request Request object.
     */
    public function update_preferences(\WP_REST_Request $request): \WP_REST_Response {
        $prefs = new ToolPreferences();
        $name = $request->get_param('name');
        $enabled = $request->get_param('enabled');

        if (is_string($name) && '' !== $name && true === $enabled) {
            $disabled = $prefs->enable_tool($name);
        } elseif (is_string($name) && '' !== $name && false === $enabled) {
            $disabled = $prefs->disable_tool($name);
        } else {
            $disabled_param = $request->get_param('disabled');
            $disabled = $prefs->set_disabled(is_array($disabled_param) ? $disabled_param : []);
        }

        return new \WP_REST_Response([
            'disabled' => $disabled,
            'never_auto' => ToolPreferences::NEVER_AUTO_EXECUTE,
            'tools' => new ToolsPayloadBuilder()->full(),
        ], 200);
    }

    public function mcp_status(): \WP_REST_Response {
        return new \WP_REST_Response(new Adapter()->get_status(), 200);
    }

    public function mcp_tools(): \WP_REST_Response {
        return new \WP_REST_Response(new Adapter()->list_tools(), 200);
    }

    /**
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function execute_mcp_tool(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $input = $request->get_param('input');
        $result = new Adapter()->execute_tool(
            rawurldecode((string) $request->get_param('name')),
            is_array($input) ? $input : [],
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, 200);
    }
}
