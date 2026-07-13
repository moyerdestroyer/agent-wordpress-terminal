<?php

/**
 * awpt/propose-new-post ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\PostContentSanitizer;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates a staged brand-new post/page action without inserting anything yet.
 *
 * Use this instead of awpt/propose-content-update when there is no existing post to
 * edit — propose-content-update can only ever modify a post that already exists.
 */
final class ProposeNewPost implements AbilityInterface {
    private ActionRepository $actions;
    private SessionRepository $sessions;
    private StagedPostPreview $preview;

    public function __construct(
        ?ActionRepository $actions = null,
        ?SessionRepository $sessions = null,
        ?StagedPostPreview $preview = null,
    ) {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->preview = $preview ?? new StagedPostPreview();
    }

    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-new-post',
            'label' => __('Propose New Post', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages creation of a brand new post or page for explicit admin approval. Use this — not '
                . 'awpt/propose-content-update — when there is no existing post to edit. Always creates as a '
                . 'draft; publishing is a separate admin decision.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => [
                        'type' => 'integer',
                        'description' => __('AWPT session ID.', 'agent-wordpress-terminal'),
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => __('Action card title.', 'agent-wordpress-terminal'),
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => __(
                            'Human-readable description of the proposed new post.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => __('Title for the new post.', 'agent-wordpress-terminal'),
                    ],
                    'post_content' => [
                        'type' => 'string',
                        'description' => __('Content for the new post.', 'agent-wordpress-terminal'),
                    ],
                    'post_type' => [
                        'type' => 'string',
                        'description' => __(
                            'Post type to create: "post" or "page". Defaults to "post".',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'featured_image_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional Media Library attachment ID to set as the post featured image. '
                            . 'Use the id returned by awpt/sideload-media when the user asks for a featured image.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['session_id', 'title', 'description', 'post_title', 'post_content'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
                'requires_approval' => true,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_propose(array $input): bool {
        unset($input);

        return current_user_can('edit_posts');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $post_title = trim(sanitize_text_field((string) ($input['post_title'] ?? '')));
        $post_content = trim((string) ($input['post_content'] ?? ''));

        if ('' === $post_title || '' === $post_content) {
            return new \WP_Error(
                code: 'awpt_invalid_new_post',
                message: __('A post title and content are required to propose a new post.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $post_type = sanitize_key((string) ($input['post_type'] ?? 'post'));

        if (!in_array($post_type, ['post', 'page'], true)) {
            return new \WP_Error(
                code: 'awpt_invalid_post_type',
                message: __('Unsupported post type. Use "post" or "page".', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $featured_image_id = (int) ($input['featured_image_id'] ?? 0);

        if ($featured_image_id > 0) {
            $validation_error = $this->validate_featured_image($featured_image_id);

            if (null !== $validation_error) {
                return new \WP_Error(code: 'awpt_invalid_featured_image', message: $validation_error, data: [
                    'status' => 400,
                ]);
            }
        }

        $payload = [
            'operation' => ActionOperations::NEW_POST,
            'post_id' => 0,
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_title' => $post_title,
            'post_content' => PostContentSanitizer::for_staged_update($post_content),
        ];

        if ($featured_image_id > 0) {
            $payload['featured_image_id'] = $featured_image_id;
        }

        $payload = $this->preview->prepare_new_post_payload($payload);

        if (is_wp_error($payload)) {
            return $payload;
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field((string) $input['title']),
            description: sanitize_textarea_field((string) $input['description']),
            payload: $payload,
        );

        if (null === $action_id) {
            $this->preview->discard_staging_draft($payload);

            return new \WP_Error(
                code: 'awpt_action_create_failed',
                message: __('Could not create proposed action.', 'agent-wordpress-terminal'),
                data: ['status' => 500],
            );
        }

        $action = $this->actions->format_action($action_id);

        return is_array($action) ? $action : [];
    }

    private function validate_featured_image(int $attachment_id): ?string {
        $attachment = get_post($attachment_id);

        if (!$attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type) {
            return __('Featured image must be a valid Media Library attachment.', 'agent-wordpress-terminal');
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return __('Featured image must be an image attachment.', 'agent-wordpress-terminal');
        }

        return null;
    }
}
