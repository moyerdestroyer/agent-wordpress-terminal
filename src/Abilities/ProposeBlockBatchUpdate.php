<?php

/**
 * awpt/propose-block-batch-update ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Domain\CompositionProposalGuard;
use AWPT\Support\ActionOperations;
use AWPT\Support\BlockBatchUpdater;
use AWPT\Support\PatternUnfitInput;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/** Stages many verified block edits as one content action. */
final class ProposeBlockBatchUpdate implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-block-batch-update',
            'label' => __('Propose Block Batch Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages one atomic existing-page update containing multiple verified attribute, rich-text, combined block, removal, or insertion changes. Each path accepts one non-insertion mutation; use update_block with both attrs and content when the same block needs attribute and rich-text changes. Use paths and fingerprints from the compose evidence pack content_reads block tree (from awpt/read-block-tree or server-side synthesis). Insertions must use a verified anchor path and before/after position.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'post_id' => ['type' => 'integer'],
                    'presentation_requires_h1' => ['type' => 'boolean'],
                    'changes' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 100,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'kind' => [
                                    'type' => 'string',
                                    'enum' => ['update_attrs', 'replace_text', 'update_block', 'remove', 'insert'],
                                    'description' => __(
                                        'Use update_block—not separate update_attrs and replace_text entries—when one path needs both attrs and content changed.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'block_path' => ['type' => 'string'],
                                'expected_fingerprint' => [
                                    'type' => 'string',
                                    'minLength' => 64,
                                    'maxLength' => 64,
                                    'pattern' => '^[a-f0-9]{64}$',
                                    'description' => __(
                                        'Copy the complete 64-character fingerprint verbatim from the verified evidence pack content_reads block tree. Never abbreviate, truncate, invent, or zero-fill it.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'attrs' => [
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                    'description' => __(
                                        'Required for update_attrs and update_block.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'content' => [
                                    'type' => 'string',
                                    'description' => __(
                                        'Required for replace_text and update_block.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'position' => ['type' => 'string', 'enum' => ['before', 'after']],
                                'block_name' => [
                                    'type' => 'string',
                                    'description' => __(
                                        'Required for insert, for example core/heading.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'inner_html' => [
                                    'type' => 'string',
                                    'description' => __(
                                        'Saved HTML for an inserted block, including its semantic wrapper (for example <h1>Title</h1> or <p>Introduction</p>).',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                            ],
                            'required' => ['kind', 'block_path', 'expected_fingerprint'],
                        ],
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    ...PatternUnfitInput::schema_properties(),
                ],
                'required' => ['session_id', 'post_id', 'changes', 'title', 'description'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => true, 'requires_approval' => true],
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

        if (!new SessionRepository()->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }
        $raw_changes = is_array($input['changes'] ?? null) ? array_values($input['changes']) : [];
        /** @var list<array<string, mixed>> $changes */
        $changes = array_values(array_filter($raw_changes, 'is_array'));
        $updater = new BlockBatchUpdater();
        $update = $updater->apply($post->post_content, $changes);

        if (is_wp_error($update)) {
            return $update;
        }

        $payload = [
            'operation' => ActionOperations::CONTENT_UPDATE,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'post_content' => $update['content'],
            'batch_changes' => $update['changes'],
            'presentation_requires_h1' => true === ($input['presentation_requires_h1'] ?? false),
            'affected' => sprintf(
                /* translators: %d: number of block changes. */
                _n(
                    '%d verified block change',
                    '%d verified block changes',
                    count($update['changes']),
                    'agent-wordpress-terminal',
                ),
                count($update['changes']),
            ),
        ];
        $payload = PatternUnfitInput::persist_on_payload($payload, $input);

        $payload = new CompositionProposalGuard()->prepare($payload, 'edit', $session_id);

        if (is_wp_error($payload)) {
            return $payload;
        }

        $preview = new StagedPostPreview();
        $preview_result = $preview->preview_from_payload($payload);

        if (is_wp_error($preview_result)) {
            return $preview_result;
        }

        $payload['preview_url'] = $preview_result['preview_url'];

        if (array_key_exists('autosave_id', $preview_result)) {
            $payload['preview_autosave_id'] = (int) $preview_result['autosave_id'];
        }

        $actions = new ActionRepository();
        $action_id = $actions->create(
            $session_id,
            sanitize_text_field((string) ($input['title'] ?? '')),
            $updater->describe($update['changes']),
            $payload,
        );

        if (null === $action_id) {
            $preview->discard_preview_resources($payload);

            return new \WP_Error(
                'awpt_action_create_failed',
                __('Could not create proposed action.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        return $actions->format_action($action_id) ?? [];
    }
}
