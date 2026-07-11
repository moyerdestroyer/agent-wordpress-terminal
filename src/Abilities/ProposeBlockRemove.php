<?php

/**
 * awpt/propose-block-remove ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\BlockTree;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stages removal of a Gutenberg block by path.
 */
final class ProposeBlockRemove {
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

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-block-remove',
            'label' => __('Propose Block Remove', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages removal of a Gutenberg block by path and optional fingerprint from awpt/list-blocks.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'post_id' => ['type' => 'integer'],
                    'block_path' => [
                        'type' => 'string',
                        'description' => __('Dotted block path to remove.', 'agent-wordpress-terminal'),
                    ],
                    'expected_fingerprint' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional fingerprint to prevent stale removals.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'post_id', 'block_path', 'title', 'description'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
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
            return new \WP_Error(
                code: 'awpt_post_not_found',
                message: __('Post not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        $block_path = sanitize_text_field((string) ($input['block_path'] ?? ''));
        $expected_fingerprint = sanitize_text_field((string) ($input['expected_fingerprint'] ?? ''));
        $update = BlockTree::from_content($post->post_content)->remove_block($block_path, $expected_fingerprint);

        if (is_wp_error($update)) {
            return $update;
        }

        $removed = $update['removed'];
        $payload = [
            'operation' => ActionOperations::BLOCK_REMOVE,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'post_content' => $update['content'],
            'block_path' => $block_path,
            'block_name' => (string) ($removed['blockName'] ?? ''),
            'expected_fingerprint' => $expected_fingerprint,
            'affected' => sprintf(
                /* translators: 1: block path, 2: block name */
                __('Remove block %1$s (%2$s)', 'agent-wordpress-terminal'),
                $block_path,
                (string) ($removed['blockName'] ?? ''),
            ),
        ];

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

            return new \WP_Error(
                code: 'awpt_action_create_failed',
                message: __('Could not create proposed action.', 'agent-wordpress-terminal'),
                data: ['status' => 500],
            );
        }

        $action = $this->actions->format_action($action_id);

        return is_array($action) ? $action : [];
    }
}
