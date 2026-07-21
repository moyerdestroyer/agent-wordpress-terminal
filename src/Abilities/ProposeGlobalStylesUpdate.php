<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/** Stages creation or update of an active-theme wp_global_styles revision. */
final class ProposeGlobalStylesUpdate implements AbilityInterface {
    private ActionRepository $actions;
    private SessionRepository $sessions;

    public function __construct(?ActionRepository $actions = null, ?SessionRepository $sessions = null) {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-global-styles-update',
            'label' => __('Propose Global Styles Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages creation or update of the active-theme global-styles revision for approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'global_styles_id' => ['type' => 'integer'],
                    'content' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'affected' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'content', 'title', 'description'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'requires_approval' => true],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_propose(array $input): bool {
        $global_styles_id = (int) ($input['global_styles_id'] ?? 0);

        return (
            current_user_can('edit_theme_options')
            && ($global_styles_id <= 0 || current_user_can('edit_post', $global_styles_id))
        );
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $global_styles_id = (int) ($input['global_styles_id'] ?? 0);
        $post = $global_styles_id > 0 ? get_post($global_styles_id) : null;

        if ($global_styles_id > 0 && (!$post instanceof \WP_Post || 'wp_global_styles' !== $post->post_type)) {
            return new \WP_Error(
                'awpt_global_styles_not_found',
                __('Global styles revision not found.', 'agent-wordpress-terminal'),
                ['status' => 404],
            );
        }

        if ($post instanceof \WP_Post && (string) get_post_meta($post->ID, 'theme', true) !== get_stylesheet()) {
            return new \WP_Error(
                'awpt_global_styles_theme_mismatch',
                __('Global styles must belong to the active theme.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $content = trim((string) $input['content']);
        $decoded_content = json_decode($content, true);

        if ('' === $content || !is_array($decoded_content)) {
            return new \WP_Error(
                'awpt_invalid_global_styles',
                __('Global styles content must be a non-empty JSON object.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $normalized_content = wp_json_encode($decoded_content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($normalized_content)) {
            return new \WP_Error(
                'awpt_global_styles_encode_failed',
                __('Global styles content could not be encoded.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $payload = [
            'operation' => $post instanceof \WP_Post
                ? ActionOperations::GLOBAL_STYLES_UPDATE
                : ActionOperations::GLOBAL_STYLES_CREATE,
            'post_id' => $post instanceof \WP_Post ? $post->ID : 0,
            'post_type' => 'wp_global_styles',
            'post_status' => $post instanceof \WP_Post ? $post->post_status : 'publish',
            'post_title' => $post instanceof \WP_Post
                ? $post->post_title
                : sprintf(__('Global Styles: %s', 'agent-wordpress-terminal'), get_stylesheet()),
            'original_post_title' => $post instanceof \WP_Post ? $post->post_title : '',
            'original_post_content' => $post instanceof \WP_Post ? $post->post_content : '',
            'original_post_status' => $post instanceof \WP_Post ? $post->post_status : '',
            'post_content' => $normalized_content,
            'global_styles_theme' => get_stylesheet(),
            'affected' => sanitize_textarea_field((string) ($input['affected'] ?? 'Global styles')),
        ];
        $action_id = $this->actions->create(
            $session_id,
            sanitize_text_field((string) $input['title']),
            sanitize_textarea_field((string) $input['description']),
            $payload,
        );

        return (
            null === $action_id
                ? new \WP_Error(
                    'awpt_action_create_failed',
                    __('Could not create proposed action.', 'agent-wordpress-terminal'),
                    ['status' => 500],
                )
                : $this->actions->format_action($action_id) ?? []
        );
    }
}
