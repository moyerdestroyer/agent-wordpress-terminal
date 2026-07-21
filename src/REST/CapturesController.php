<?php

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Database\CaptureRepository;
use AWPT\Database\SessionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/** Receives bounded, same-origin preview evidence from the admin iframe. */
final class CapturesController extends RestController {
    private const MAX_DOM_CHARS = 50_000;
    private const MAX_IMAGE_CHARS = 4_000_000;

    public function register_routes(): void {
        register_rest_route(AWPT_REST_NAMESPACE, '/sessions/(?P<session_id>\d+)/captures', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_capture'],
            'permission_callback' => [$this, 'can_manage'],
        ]]);
    }

    public function create_capture(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $session_id = RequestParams::int($request, 'session_id');

        if (!new SessionRepository()->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $dom_snapshot = mb_substr((string) $request->get_param('dom_snapshot'), 0, self::MAX_DOM_CHARS);
        $image_data = (string) $request->get_param('image_data');

        if (
            '' !== $image_data
            && (!str_starts_with($image_data, 'data:image/') || strlen($image_data) > self::MAX_IMAGE_CHARS)
        ) {
            return new \WP_Error(
                'awpt_invalid_capture_image',
                __('Capture image must be a bounded image data URL.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        if ('' === trim($dom_snapshot) && '' === $image_data) {
            return new \WP_Error(
                'awpt_empty_capture',
                __('A capture needs visual or DOM evidence.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $viewport = $request->get_param('viewport');
        $capture_id = new CaptureRepository()->create([
            'session_id' => $session_id,
            'action_id' => RequestParams::int($request, 'action_id'),
            'post_id' => RequestParams::int($request, 'post_id'),
            'url' => esc_url_raw(RequestParams::string($request, 'url')),
            'viewport' => is_array($viewport) ? $viewport : [],
            'dom_snapshot' => $dom_snapshot,
            'image_data' => $image_data,
        ]);

        if (null === $capture_id) {
            return new \WP_Error(
                'awpt_capture_create_failed',
                __('Could not save preview evidence.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        return new \WP_REST_Response(['id' => $capture_id, 'has_image' => '' !== $image_data], 201);
    }
}
