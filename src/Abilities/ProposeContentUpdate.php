<?php

/**
 * awpt/propose-content-update ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\NewPostStagingDraft;
use AWPT\Support\PostContentSanitizer;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates a staged content update action without saving the post.
 */
final class ProposeContentUpdate implements AbilityInterface {
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
            'name' => 'awpt/propose-content-update',
            'label' => __('Propose Content Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a proposed post update (title, content, status, or meta) for explicit admin approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => [
                        'type' => 'integer',
                        'description' => __('AWPT session ID.', 'agent-wordpress-terminal'),
                    ],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => __('Post ID to update.', 'agent-wordpress-terminal'),
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => __('Action card title.', 'agent-wordpress-terminal'),
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => __(
                            'Human-readable description of the proposed update.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => __('Optional replacement post title.', 'agent-wordpress-terminal'),
                    ],
                    'post_content' => [
                        'type' => 'string',
                        'description' => __('Optional replacement post content.', 'agent-wordpress-terminal'),
                    ],
                    'post_status' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional replacement post status (publish, draft, pending, private, future).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_meta' => [
                        'type' => 'object',
                        'description' => __(
                            'Optional post meta key/value pairs to update on approval.',
                            'agent-wordpress-terminal',
                        ),
                        'additionalProperties' => true,
                    ],
                    'affected' => [
                        'type' => 'string',
                        'description' => __('Affected block range or content area.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['session_id', 'post_id', 'title', 'description'],
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
        $post_id = (int) ($input['post_id'] ?? 0);

        return $post_id > 0 && current_user_can('edit_post', $post_id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $post_id = (int) ($input['post_id'] ?? 0);
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(code: 'awpt_post_not_found', message: __(
                'Post not found.',
                'agent-wordpress-terminal',
            ));
        }

        if (new NewPostStagingDraft()->is_staging_draft($post_id)) {
            return new \WP_Error(
                code: 'awpt_staging_draft_not_content',
                message: __(
                    'This post is a temporary new-post preview. Revise its staged proposal with '
                    . 'awpt/propose-new-post and the proposal action_id instead.',
                    'agent-wordpress-terminal',
                ),
                data: ['status' => 409],
            );
        }

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(code: 'awpt_session_not_found', message: __(
                'Session not found.',
                'agent-wordpress-terminal',
            ));
        }

        $payload = [
            'operation' => ActionOperations::CONTENT_UPDATE,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
        ];

        if (array_key_exists('post_title', $input)) {
            $payload['post_title'] = sanitize_text_field((string) $input['post_title']);
        }

        if (array_key_exists('post_content', $input)) {
            $payload['post_content'] = PostContentSanitizer::for_staged_update((string) $input['post_content']);
        }

        if (array_key_exists('post_status', $input)) {
            $status = sanitize_key((string) $input['post_status']);

            if (!in_array($status, array_keys(get_post_statuses()), true)) {
                return new \WP_Error(code: 'awpt_invalid_post_status', message: __(
                    'Unsupported post status.',
                    'agent-wordpress-terminal',
                ));
            }

            $payload['post_status'] = $status;
        }

        if (array_key_exists('post_meta', $input) && is_array($input['post_meta'])) {
            $meta_changes = [];
            $original_meta = [];

            foreach ($input['post_meta'] as $key => $value) {
                $meta_key = sanitize_key((string) $key);

                if ('' === $meta_key) {
                    continue;
                }

                $meta_changes[$meta_key] = $this->sanitize_meta_value($value);
                $original_meta[$meta_key] = get_post_meta($post_id, $meta_key, true);
            }

            if ([] !== $meta_changes) {
                $payload['post_meta'] = $meta_changes;
                $payload['original_post_meta'] = $original_meta;
            }
        }

        if (array_key_exists('affected', $input)) {
            $payload['affected'] = sanitize_textarea_field((string) $input['affected']);
        }

        $preview = $this->preview->preview_from_payload($payload);

        if (is_wp_error($preview)) {
            return $preview;
        }

        $payload['preview_url'] = $preview['preview_url'];

        if (array_key_exists('autosave_id', $preview)) {
            $payload['preview_autosave_id'] = (int) $preview['autosave_id'];
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field((string) $input['title']),
            description: sanitize_textarea_field((string) $input['description']),
            payload: $payload,
        );

        if (null === $action_id) {
            $this->preview->discard_preview_resources($payload);

            return new \WP_Error(code: 'awpt_action_create_failed', message: __(
                'Could not create proposed action.',
                'agent-wordpress-terminal',
            ));
        }

        $action = $this->actions->format_action($action_id);

        return is_array($action) ? $action : [];
    }

    private function sanitize_meta_value(mixed $value): string|int|float|bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }
}
