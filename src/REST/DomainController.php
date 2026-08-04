<?php

/**
 * Domain Pack status and administrator activation controls.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Domain\DomainPackHealth;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\DomainPatternCatalogView;

if (!defined('ABSPATH')) {
    exit();
}

final class DomainController extends RestController {
    public function register_routes(): void {
        register_rest_route(AWPT_REST_NAMESPACE, '/domain-packs', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'status'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
    }

    public function status(): \WP_REST_Response {
        return new \WP_REST_Response([
            'packs' => DomainPackRegistry::instance()->status(),
            'health' => new DomainPackHealth()->report(),
            'patterns' => new DomainPatternCatalogView()->items(),
            'knowledge_backend' => post_type_exists('wp_knowledge')
                ? 'wp_knowledge'
                : (post_type_exists('wp_guideline') ? 'wp_guideline' : ''),
        ], 200);
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response {
        $disabled = $request->get_param('disabled');
        $values = is_array($disabled) ? array_map('strval', $disabled) : [];
        update_option(
            'awpt_disabled_domain_packs',
            DomainPackRegistry::instance()->sanitize_disabled_ids($values),
            false,
        );

        return $this->status();
    }
}
