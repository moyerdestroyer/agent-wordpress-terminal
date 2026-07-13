<?php

/**
 * awpt/propose-block-insert ability.
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
use AWPT\Support\BlockTreeEditor;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stages insertion of a Gutenberg block at a path.
 */
final class ProposeBlockInsert implements AbilityInterface {
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
            'name' => 'awpt/propose-block-insert',
            'label' => __('Propose Block Insert', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages insertion of a Gutenberg block at a path (before, after, or append). Use paths from awpt/list-blocks.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'post_id' => ['type' => 'integer'],
                    'block_path' => [
                        'type' => 'string',
                        'description' => __(
                            'Reference path for before/after, or parent path for append (empty string = root append).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'position' => [
                        'type' => 'string',
                        'description' => __('before, after, or append (default after).', 'agent-wordpress-terminal'),
                    ],
                    'block_name' => [
                        'type' => 'string',
                        'description' => __(
                            'Block name such as core/paragraph or core/spacer.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'attrs' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                    'inner_html' => [
                        'type' => 'string',
                        'description' => __('Optional innerHTML for the block.', 'agent-wordpress-terminal'),
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'post_id', 'block_name', 'title', 'description'],
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

        $block_name = sanitize_text_field((string) ($input['block_name'] ?? ''));

        if ('' === $block_name || !preg_match('/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/', $block_name)) {
            return new \WP_Error(
                code: 'awpt_invalid_block_name',
                message: __('Block name must look like namespace/block-name.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $attrs = is_array($input['attrs'] ?? null)
            ? new ActionPayloadSanitizer()->sanitize_attrs_map($input['attrs'])
            : [];
        $inner_html = wp_kses_post((string) ($input['inner_html'] ?? ''));
        $block = new BlockTreeEditor()->normalize_block([
            'blockName' => $block_name,
            'attrs' => $attrs,
            'innerHTML' => $inner_html,
            'innerBlocks' => [],
            'innerContent' => [$inner_html],
        ]);
        $path = sanitize_text_field((string) ($input['block_path'] ?? ''));
        $position = sanitize_key((string) ($input['position'] ?? BlockTree::POSITION_AFTER));
        $update = BlockTree::from_content($post->post_content)->insert_block($path, $block, $position);

        if (is_wp_error($update)) {
            return $update;
        }

        $payload = [
            'operation' => ActionOperations::BLOCK_INSERT,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'post_content' => $update['content'],
            'block_path' => $path,
            'inserted_path' => $update['path'],
            'block_name' => $block_name,
            'position' => $position,
            'block' => $block,
            'attrs' => $attrs,
            'affected' => sprintf(
                /* translators: 1: position, 2: path, 3: block name */
                __('Insert %1$s path %2$s (%3$s)', 'agent-wordpress-terminal'),
                $position,
                $update['path'],
                $block_name,
            ),
        ];

        return $this->stage($session_id, $input, $payload);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    private function stage(int $session_id, array $input, array $payload): array|\WP_Error {
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
