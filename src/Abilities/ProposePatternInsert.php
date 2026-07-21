<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\BlockTree;
use AWPT\Support\PatternCatalog;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/** Stages insertion of an existing WordPress pattern as an ordered block composition. */
final class ProposePatternInsert implements AbilityInterface {
    private ActionRepository $actions;
    private SessionRepository $sessions;
    private StagedPostPreview $preview;
    private PatternCatalog $patterns;

    public function __construct(
        ?ActionRepository $actions = null,
        ?SessionRepository $sessions = null,
        ?StagedPostPreview $preview = null,
        ?PatternCatalog $patterns = null,
    ) {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->preview = $preview ?? new StagedPostPreview();
        $this->patterns = $patterns ?? new PatternCatalog();
    }

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-pattern-insert',
            'label' => __('Propose Pattern Insert', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages insertion of a registered or reusable pattern at a Gutenberg block path for approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'post_id' => ['type' => 'integer'],
                    'pattern_name' => ['type' => 'string'],
                    'block_path' => ['type' => 'string'],
                    'position' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'post_id', 'pattern_name', 'title', 'description'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'requires_approval' => true],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_propose(array $input): bool {
        $post_id = (int) ($input['post_id'] ?? 0);
        return $post_id > 0 && current_user_can('edit_post', $post_id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $post_id = (int) ($input['post_id'] ?? 0);
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $pattern = $this->patterns->find((string) ($input['pattern_name'] ?? ''));

        if (null === $pattern) {
            return new \WP_Error('awpt_pattern_not_found', __('Pattern not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $blocks = array_values(array_filter(
            parse_blocks((string) ($pattern['content'] ?? '')),
            BlockTree::has_block_name(...),
        ));
        $path = sanitize_text_field((string) ($input['block_path'] ?? ''));
        $position = sanitize_key((string) ($input['position'] ?? BlockTree::POSITION_APPEND));
        $update = BlockTree::from_content($post->post_content)->insert_blocks($path, $blocks, $position);

        if (is_wp_error($update)) {
            return $update;
        }

        $summary = $this->patterns->summary($pattern);
        $payload = [
            'operation' => ActionOperations::PATTERN_INSERT,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'post_content' => $update['content'],
            'block_path' => $path,
            'position' => $position,
            'pattern_name' => $summary['name'],
            'pattern_title' => $summary['title'],
            'pattern_source' => $summary['source'],
            'blocks' => $update['blocks'],
            'inserted_paths' => $update['paths'],
            'affected' => sprintf(__('Insert pattern %s', 'agent-wordpress-terminal'), (string) $summary['title']),
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
            $session_id,
            sanitize_text_field((string) $input['title']),
            sanitize_textarea_field((string) $input['description']),
            $payload,
        );

        if (null === $action_id) {
            $this->preview->discard_preview_resources($payload);
            return new \WP_Error(
                'awpt_action_create_failed',
                __('Could not create proposed action.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        return $this->actions->format_action($action_id) ?? [];
    }
}
