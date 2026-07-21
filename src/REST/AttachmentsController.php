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

/** Uploads explicit composer attachments into the WordPress Media Library. */
final class AttachmentsController extends RestController {
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
                __('Choose an image to upload.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }
        /** @var array{error?: int, name?: string, size?: int, tmp_name?: string, type?: string} $file */
        if (!str_starts_with((string) ($file['type'] ?? ''), 'image/')) {
            return new \WP_Error(
                'awpt_attachment_type',
                __('Only image attachments are supported.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $upload = wp_handle_upload($file, ['test_form' => false]);
        if ('' !== (string) ($upload['error'] ?? '')) {
            return new \WP_Error(
                'awpt_attachment_upload',
                (string) ($upload['error'] ?? __('Upload failed.', 'agent-wordpress-terminal')),
                ['status' => 500],
            );
        }
        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => (string) $upload['type'],
                'post_title' => sanitize_file_name((string) ($file['name'] ?? 'Attachment')),
                'post_status' => 'inherit',
            ],
            (string) $upload['file'],
            0,
            true,
        );
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata(
            $attachment_id,
            (string) $upload['file'],
        ));
        return new \WP_REST_Response([
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'mime_type' => get_post_mime_type($attachment_id),
            'filename' => basename((string) $upload['file']),
        ], 201);
    }
}
