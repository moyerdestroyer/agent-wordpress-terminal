<?php

/**
 * awpt/propose-block-attrs-update ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionPayloadSanitizer;
use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\BlockTree;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stages a surgical Gutenberg block attribute update.
 */
final class ProposeBlockAttrsUpdate implements AbilityInterface {
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
            'name' => 'awpt/propose-block-attrs-update',
            'label' => __('Propose Block Attribute Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a targeted Gutenberg block attribute update by post ID and block path.',
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
                    'block_path' => [
                        'type' => 'string',
                        'description' => __(
                            'Dotted zero-based visible block path from awpt/read-block-tree.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'expected_fingerprint' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional fingerprint from awpt/read-block-tree to prevent stale proposals.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'attrs' => [
                        'type' => 'object',
                        'description' => __(
                            'Block attributes to merge onto the target block.',
                            'agent-wordpress-terminal',
                        ),
                        'additionalProperties' => true,
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
                ],
                'required' => ['session_id', 'post_id', 'block_path', 'attrs', 'title', 'description'],
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

        $attrs = is_array($input['attrs'] ?? null) ? $input['attrs'] : [];
        $attrs = new ActionPayloadSanitizer()->sanitize_attrs_map($attrs);

        if ([] === $attrs) {
            return new \WP_Error(
                code: 'awpt_empty_block_attrs',
                message: __('At least one block attribute is required.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $block_path = sanitize_text_field((string) ($input['block_path'] ?? ''));
        $expected_fingerprint = sanitize_text_field((string) ($input['expected_fingerprint'] ?? ''));
        $update = BlockTree::from_content($post->post_content)->update_attrs(
            $block_path,
            $attrs,
            $expected_fingerprint,
        );

        if (is_wp_error($update)) {
            return $update;
        }

        $payload = [
            'operation' => ActionOperations::BLOCK_ATTRS_UPDATE,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'post_content' => $update['content'],
            'block_path' => $block_path,
            'block_name' => (string) ($update['block']['blockName'] ?? ''),
            'expected_fingerprint' => $expected_fingerprint,
            'attrs' => $attrs,
            'affected' => sprintf(
                /* translators: %s: block path. */
                __('Block path %s', 'agent-wordpress-terminal'),
                $block_path,
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
