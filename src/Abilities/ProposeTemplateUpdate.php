<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\PostContentSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/** Stages a full-template or template-part block update for explicit approval. */
final class ProposeTemplateUpdate implements AbilityInterface {
    private ActionRepository $actions;
    private SessionRepository $sessions;

    public function __construct(?ActionRepository $actions = null, ?SessionRepository $sessions = null) {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-template-update',
            'label' => __('Propose Template Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a Gutenberg template or template-part update for explicit admin approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'template_id' => ['type' => 'integer'],
                    'content' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'affected' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'template_id', 'content', 'title', 'description'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'requires_approval' => true],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_propose(array $input): bool {
        return (
            current_user_can('edit_theme_options') && current_user_can('edit_post', (int) ($input['template_id'] ?? 0))
        );
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $post = get_post((int) ($input['template_id'] ?? 0));

        if (!$post instanceof \WP_Post || !in_array($post->post_type, ['wp_template', 'wp_template_part'], true)) {
            return new \WP_Error('awpt_template_not_found', __('Template not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $content = PostContentSanitizer::for_staged_update((string) $input['content']);

        if ('' === trim($content)) {
            return new \WP_Error(
                'awpt_empty_template',
                __('Template content cannot be empty.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $payload = [
            'operation' => ActionOperations::TEMPLATE_UPDATE,
            'post_id' => $post->ID,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_title' => $post->post_title,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'post_content' => $content,
            'template_type' => $post->post_type,
            'template_area' => (string) get_post_meta($post->ID, 'area', true),
            'affected' => sanitize_textarea_field((string) ($input['affected'] ?? $post->post_name)),
        ];
        $action_id = $this->actions->create(
            $session_id,
            sanitize_text_field((string) $input['title']),
            sanitize_textarea_field((string) $input['description']),
            $payload,
        );

        if (null === $action_id) {
            return new \WP_Error(
                'awpt_action_create_failed',
                __('Could not create proposed action.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        return $this->actions->format_action($action_id) ?? [];
    }
}
