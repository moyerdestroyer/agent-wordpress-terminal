<?php

/**
 * REST endpoint for composer media attachments.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

if (!defined('ABSPATH')) {
    exit();
}

/** Uploads explicit composer evidence into the WordPress Media Library. */
final class AttachmentsController extends RestController {
    /** @var list<string> */
    private const DOCUMENT_EXTENSIONS = ['pdf', 'txt', 'md', 'markdown', 'csv', 'json', 'xml', 'docx'];

    public function register_routes(): void {
        register_rest_route(AWPT_REST_NAMESPACE, '/attachments', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upload'],
            'permission_callback' => [$this, 'can_manage'],
        ]]);
    }

    public function upload(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        if (!current_user_can('upload_files')) {
            return new \WP_Error(
                'awpt_cannot_upload',
                __('You do not have permission to upload files.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $files = $request->get_file_params();
        $file = $files['file'] ?? null;
        if (!is_array($file) || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return new \WP_Error(
                'awpt_attachment_required',
                __('Choose an image or document to upload.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }
        /** @var array{error?: int, name?: string, size?: int, tmp_name?: string, type?: string} $file */
        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $declared_mime = strtolower($file['type'] ?? '');
        $supported = str_starts_with($declared_mime, 'image/') || in_array($extension, self::DOCUMENT_EXTENSIONS, true);

        if (!$supported) {
            return new \WP_Error(
                'awpt_attachment_type',
                __(
                    'Supported composer files are images, PDF, plain text, Markdown, CSV, JSON, XML, and DOCX.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 400],
            );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        // media_handle_upload performs WordPress's extension/MIME validation and
        // creates metadata using the site's configured upload policy.
        $attachment_id = media_handle_upload('file', 0, [], ['test_form' => false]);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        $path = get_attached_file($attachment_id);
        $is_image = wp_attachment_is_image($attachment_id);

        return new \WP_REST_Response([
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'mime_type' => get_post_mime_type($attachment_id),
            'filename' => is_string($path) ? basename($path) : sanitize_file_name($file['name'] ?? ''),
            'kind' => $is_image ? 'image' : 'document',
        ], 201);
    }
}
