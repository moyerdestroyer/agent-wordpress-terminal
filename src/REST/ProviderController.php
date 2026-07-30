<?php

/**
 * Provider-related REST endpoints.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Agent\OpenRouterBilling;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Exposes active-provider metadata such as OpenRouter usage/billing.
 */
final class ProviderController extends RestController {
    /**
     * Register routes.
     */
    public function register_routes(): void {
        register_rest_route(AWPT_REST_NAMESPACE, '/provider/billing', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'billing'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'refresh' => [
                        'type' => 'boolean',
                        'default' => false,
                        'required' => false,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Return OpenRouter billing/usage when that provider is selected.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function billing(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $refresh = $request->get_param('refresh');
        $billing = new OpenRouterBilling();
        $summary =
            true === $refresh || 1 === $refresh || '1' === $refresh || 'true' === $refresh
                ? $billing->refresh_summary()
                : $billing->get_summary();

        if (is_wp_error($summary)) {
            return $summary;
        }

        return new \WP_REST_Response($summary, 200);
    }
}
