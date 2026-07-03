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

defined('ABSPATH') || exit();

/**
 * Creates a staged content update action without saving the post.
 */
final class ProposeContentUpdate
{
    private ActionRepository $actions;
    private SessionRepository $sessions;

    public function __construct(?ActionRepository $actions = null, ?SessionRepository $sessions = null)
    {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * Register the ability.
     */
    public function register(): void
    {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-content-update',
            'label' => __('Propose Content Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a proposed post content update for explicit admin approval.',
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
    public function can_propose(array $input): bool
    {
        $post_id = (int) ($input['post_id'] ?? 0);

        return (
            $post_id > 0
            && current_user_can('edit_post', $post_id)
            && current_user_can(capability: 'manage_options')
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error
    {
        $session_id = (int) ($input['session_id'] ?? 0);
        $post_id = (int) ($input['post_id'] ?? 0);
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(code: 'awpt_post_not_found', message: __(
                'Post not found.',
                'agent-wordpress-terminal',
            ));
        }

        if (!$this->sessions->exists($session_id) || !current_user_can(capability: 'manage_options')) {
            return new \WP_Error(code: 'awpt_session_not_found', message: __(
                'Session not found.',
                'agent-wordpress-terminal',
            ));
        }

        $preview_url = get_preview_post_link($post);

        if (!is_string($preview_url) || '' === $preview_url) {
            $preview_url = get_permalink($post);
        }

        $payload = [
            'operation' => 'content_update',
            'post_id' => $post_id,
            'post_type' => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'original_post_title' => (string) $post->post_title,
            'original_post_content' => (string) $post->post_content,
            'preview_url' => is_string($preview_url) ? $preview_url : '',
        ];

        if (array_key_exists('post_title', $input)) {
            $payload['post_title'] = sanitize_text_field((string) $input['post_title']);
        }

        if (array_key_exists('post_content', $input)) {
            $payload['post_content'] = wp_kses_post((string) $input['post_content']);
        }

        if (array_key_exists('affected', $input)) {
            $payload['affected'] = sanitize_textarea_field((string) $input['affected']);
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field((string) $input['title']),
            description: sanitize_textarea_field((string) $input['description']),
            payload: $payload,
        );

        if (null === $action_id) {
            return new \WP_Error(code: 'awpt_action_create_failed', message: __(
                'Could not create proposed action.',
                'agent-wordpress-terminal',
            ));
        }

        $action = $this->actions->format_action($action_id);

        return is_array($action) ? $action : [];
    }
}
