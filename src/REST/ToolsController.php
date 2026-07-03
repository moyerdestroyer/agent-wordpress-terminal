<?php

/**
 * Tools REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\MCP\Adapter;
use AWPT\MCP\StatusService;
use AWPT\Support\Environment;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Exposes registered abilities and MCP status.
 */
final class ToolsController
{
    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route(AWPT_REST_NAMESPACE, '/tools', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'list_tools'],
                'permission_callback' => [$this, 'can_manage'],
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

    /**
     * Permission check.
     */
    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * List registered WordPress abilities grouped by source.
     *
     * @return \WP_REST_Response
     */
    public function list_tools(): \WP_REST_Response
    {
        $tools = [
            'core' => [],
            'plugin' => [],
            'mcp' => new Adapter()->list_tools(),
            'environment' => Environment::status(),
        ];

        if (!function_exists('wp_get_abilities')) {
            return new \WP_REST_Response($tools, 200);
        }

        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            $item = [
                'name' => $name,
                'label' => $ability->get_label(),
                'description' => $ability->get_description(),
                'category' => $ability->get_category(),
                'input_schema' => method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null,
                'output_schema' => method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null,
                'permission' => null,
                'readonly' => null,
                'destructive' => null,
                'requires_approval' => null,
            ];

            if (method_exists($ability, 'get_meta')) {
                $meta = $ability->get_meta();
                $annotations = $meta['annotations'] ?? [];

                if (is_array($annotations)) {
                    $item['readonly'] = $annotations['readonly'] ?? null;
                    $item['destructive'] = $annotations['destructive'] ?? null;
                    $item['requires_approval'] = $annotations['requires_approval'] ?? null;
                }
            }

            if (str_starts_with($name, 'core/')) {
                $tools['core'][] = $item;
                continue;
            }

            if (str_starts_with($name, 'awpt/')) {
                $tools['plugin'][] = $item;
                continue;
            }
        }

        return new \WP_REST_Response($tools, 200);
    }

    /**
     * Return MCP connection status.
     *
     * @return \WP_REST_Response
     */
    public function mcp_status(): \WP_REST_Response
    {
        return new \WP_REST_Response(new StatusService()->get_status(), 200);
    }

    /**
     * Return MCP tool metadata.
     *
     * @return \WP_REST_Response
     */
    public function mcp_tools(): \WP_REST_Response
    {
        return new \WP_REST_Response(new Adapter()->list_tools(), 200);
    }

    /**
     * Execute a non-destructive MCP tool.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function execute_mcp_tool(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
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
