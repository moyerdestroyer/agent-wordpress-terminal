<?php

/**
 * Knowledge REST controller.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Knowledge\EmbeddingService;
use AWPT\Knowledge\FilesystemAccessPolicy;
use AWPT\Knowledge\FilesystemSourceReader;
use AWPT\Knowledge\KnowledgeIndexer;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Exposes Knowledge status, settings, and rebuild.
 */
final class KnowledgeController extends RestController {
    public function register_routes(): void {
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

    public function status(): \WP_REST_Response {
        return new \WP_REST_Response(new KnowledgeIndexer()->status(), 200);
    }

    public function rebuild(): \WP_REST_Response {
        try {
            $result = new KnowledgeIndexer()->rebuild();
        } catch (\Throwable $throwable) {
            update_option('awpt_knowledge_last_error', $throwable->getMessage(), false);
            KnowledgeIndexer::mark_rebuild_failed();

            return new \WP_REST_Response([
                'error' => $throwable->getMessage(),
                'status' => new KnowledgeIndexer()->status(),
            ], 500);
        }

        return new \WP_REST_Response(array_merge($result, [
            'status' => new KnowledgeIndexer()->status(),
        ]), 200);
    }

    public function settings(): \WP_REST_Response {
        return new \WP_REST_Response($this->settings_payload(), 200);
    }

    public function update_settings(\WP_REST_Request $request): \WP_REST_Response {
        $roots = $request->get_param('roots');
        $max_file_size = (int) $request->get_param('max_file_size');
        $reader = new FilesystemSourceReader();
        $sanitized_roots = $reader->sanitize_configured_roots(is_array($roots) ? array_map('strval', $roots) : []);

        update_option('awpt_knowledge_roots', implode("\n", $sanitized_roots), false);
        KnowledgeIndexer::mark_stale();

        if ($max_file_size > 0) {
            update_option('awpt_knowledge_max_file_size', max(1024, min($max_file_size, 20_971_520)), false);
        }

        if (null !== $request->get_param('embeddings_enabled')) {
            $enabled = $request->get_param('embeddings_enabled');
            $is_on = true === $enabled || 1 === $enabled || '1' === $enabled || 'true' === $enabled;
            update_option(EmbeddingService::OPTION_ENABLED, $is_on ? '1' : '0', false);
        }

        if (null !== $request->get_param('embedding_model')) {
            $model = sanitize_text_field((string) $request->get_param('embedding_model'));
            update_option(
                EmbeddingService::OPTION_MODEL,
                '' !== $model ? $model : EmbeddingService::DEFAULT_MODEL,
                false,
            );
        }

        return new \WP_REST_Response($this->settings_payload(), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings_payload(): array {
        $reader = new FilesystemSourceReader();
        $embeddings = new EmbeddingService();
        $split = preg_split('/\R+/', (string) get_option('awpt_knowledge_roots', ''));

        return [
            'roots' => array_values(array_filter(is_array($split) ? $split : [])),
            'allowed_roots' => $reader->allowed_roots(),
            'max_file_size' => (int) get_option(
                'awpt_knowledge_max_file_size',
                FilesystemAccessPolicy::DEFAULT_MAX_FILE_SIZE,
            ),
            'embeddings_enabled' => '1' === (string) get_option(EmbeddingService::OPTION_ENABLED, '1'),
            'embeddings_available' => $embeddings->is_available(),
            'embedding_model' => $embeddings->model(),
            'embedding_provider' => $embeddings->provider_label(),
        ];
    }
}
