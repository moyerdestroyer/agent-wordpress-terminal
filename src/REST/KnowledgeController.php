<?php

/**
 * Knowledge REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Knowledge\FilesystemSourceReader;
use AWPT\Knowledge\KnowledgeIndexer;
use AWPT\Knowledge\KnowledgeSearchService;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Exposes Knowledge status, settings, rebuild, and search.
 */
final class KnowledgeController
{
    public function register_routes(): void
    {
        register_rest_route(AWPT_REST_NAMESPACE, '/knowledge/status', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'status'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/knowledge/rebuild', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rebuild'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/knowledge/search', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'search'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'query' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        register_rest_route(AWPT_REST_NAMESPACE, '/knowledge/settings', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'settings'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_settings'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(): \WP_REST_Response
    {
        return new \WP_REST_Response(new KnowledgeIndexer()->status(), 200);
    }

    public function rebuild(): \WP_REST_Response
    {
        try {
            $result = new KnowledgeIndexer()->rebuild();
        } catch (\Throwable $throwable) {
            update_option('awpt_knowledge_last_error', $throwable->getMessage(), false);

            return new \WP_REST_Response([
                'error' => $throwable->getMessage(),
                'status' => new KnowledgeIndexer()->status(),
            ], 500);
        }

        return new \WP_REST_Response(array_merge($result, [
            'status' => new KnowledgeIndexer()->status(),
        ]), 200);
    }

    public function search(\WP_REST_Request $request): \WP_REST_Response
    {
        $query = (string) $request->get_param('query');

        return new \WP_REST_Response([
            'items' => new KnowledgeSearchService()->search($query, 10),
        ], 200);
    }

    public function settings(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->settings_payload(), 200);
    }

    public function update_settings(\WP_REST_Request $request): \WP_REST_Response
    {
        $roots = $request->get_param('roots');
        $max_file_size = (int) $request->get_param('max_file_size');
        $reader = new FilesystemSourceReader();
        $sanitized_roots = $reader->sanitize_configured_roots(is_array($roots) ? array_map('strval', $roots) : []);

        update_option('awpt_knowledge_roots', implode("\n", $sanitized_roots), false);
        KnowledgeIndexer::mark_stale();

        if ($max_file_size > 0) {
            update_option('awpt_knowledge_max_file_size', max(1024, min($max_file_size, 10_485_760)), false);
        }

        return new \WP_REST_Response($this->settings_payload(), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings_payload(): array
    {
        $reader = new FilesystemSourceReader();

        return [
            'roots' => array_values(
                array_filter(
                    (static function (): array {
                        $split = preg_split('/\R+/', (string) get_option('awpt_knowledge_roots', ''));

                        return is_array($split) ? $split : [];
                    })(),
                ),
            ),
            'allowed_roots' => $reader->allowed_roots(),
            'max_file_size' => (int) get_option('awpt_knowledge_max_file_size', 2_097_152),
        ];
    }
}
