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
                'Stages one atomic existing-page update. Each change is kind set, remove, or insert on a verified block_path and expected_fingerprint from awpt/read-block-tree. For set, send attrs and/or html. For inserted headings or paragraphs, send content on the insert itself; the server builds the semantic wrapper.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional staged candidate to revise in place. Paths and fingerprints must come from reading this action_id.',
                            'agent-wordpress-terminal',
                        ),
                    ],
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
                                    'enum' => [
                                        'set',
                                        'remove',
                                        'insert',
                                        'update_attrs',
                                        'replace_text',
                                        'replace_inner_html',
                                        'update_block',
                                    ],
                                    'description' => __(
                                        'Prefer set (attrs and/or html), remove, or insert. Legacy kind names still work.',
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
                                        'For set, attributes to merge. For insert, attributes of the new block (for example heading level).',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'html' => [
                                    'type' => 'string',
                                    'maxLength' => 20_000,
                                    'description' => __(
                                        'For set, leaf inner HTML from awpt/get-block without Gutenberg comments.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'content' => [
                                    'type' => 'string',
                                    'description' => __(
                                        'Rich text for set when html is not used, or text for an inserted heading/paragraph.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'inner_html' => [
                                    'type' => 'string',
                                    'maxLength' => 20_000,
                                    'description' => __(
                                        'Alias of html. For insert, markup of the new block including its semantic wrapper.',
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
        $action_id = (int) ($input['action_id'] ?? 0);
        $actions = new ActionRepository();
        $existing_action = $action_id > 0 ? $actions->format_action($action_id) : null;
        $existing_payload = is_array($existing_action['payload'] ?? null) ? $existing_action['payload'] : [];
        $post_id = $action_id > 0 ? (int) ($existing_payload['post_id'] ?? 0) : (int) ($input['post_id'] ?? 0);
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
        if (
            $action_id > 0
            && (
                null === $existing_action
                || (int) ($existing_action['session_id'] ?? 0) !== $session_id
                || !in_array((string) ($existing_action['status'] ?? ''), ['verifying', 'proposed', 'approved'], true)
            )
        ) {
            return new \WP_Error(
                'awpt_candidate_not_revisable',
                __('The staged candidate is no longer available for revision.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }
        $raw_changes = is_array($input['changes'] ?? null) ? array_values($input['changes']) : [];
        /** @var list<array<string, mixed>> $changes */
        $changes = array_values(array_filter($raw_changes, 'is_array'));
        $updater = new BlockBatchUpdater();
        $baseline_content = $action_id > 0 ? (string) ($existing_payload['post_content'] ?? '') : $post->post_content;
        $update = $updater->apply($baseline_content, $changes);

        if (is_wp_error($update)) {
            return $update;
        }

        $payload = [
            ...$existing_payload,
            'operation' => ActionOperations::CONTENT_UPDATE,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => (string) ($existing_payload['original_post_title'] ?? $post->post_title),
            'original_post_content' => (string) ($existing_payload['original_post_content'] ?? $post->post_content),
            'original_post_status' => (string) ($existing_payload['original_post_status'] ?? $post->post_status),
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

        $title = sanitize_text_field((string) ($input['title'] ?? ''));
        $description = $updater->describe($update['changes']);
        if ($action_id > 0) {
            $saved = $actions->revise($action_id, $title, $description, $payload);
        } else {
            $created_action_id = $actions->create($session_id, $title, $description, $payload);
            $saved = null !== $created_action_id;
            $action_id = $created_action_id ?? 0;
        }

        if (!$saved || $action_id <= 0) {
            $preview->discard_preview_resources($payload);

            return new \WP_Error(
                'awpt_action_create_failed',
                __('Could not create proposed action.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        if ([] !== $existing_payload) {
            $preview->discard_preview_resources($existing_payload);
        }

        $action = $actions->format_action($action_id) ?? [];
        if ([] !== $existing_payload) {
            $action['revised_action_id'] = $action_id;
            $action['revision_kind'] = 'candidate_block_batch';
        }

        return $action;
    }
}
