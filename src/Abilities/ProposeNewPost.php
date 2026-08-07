<?php

/**
 * awpt/propose-new-post ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Domain\CompositionGate;
use AWPT\Domain\PatternCompositionBuilder;
use AWPT\Domain\PatternMaterializer;
use AWPT\Domain\PatternStructureCompleter;
use AWPT\Domain\PatternTemplateExpander;
use AWPT\Support\ActionOperations;
use AWPT\Support\PatternCatalog;
use AWPT\Support\PatternCompositionPolicy;
use AWPT\Support\PostCompositionValidator;
use AWPT\Support\PostContentMediaIntegrity;
use AWPT\Support\PostContentSanitizer;
use AWPT\Support\PostContentStagingPipeline;
use AWPT\Support\StagedPostPreview;
use AWPT\Support\ThemePostTitleStrategy;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates a staged brand-new post/page action without inserting anything yet.
 *
 * Use this instead of awpt/propose-content-update when there is no existing post to
 * edit — propose-content-update can only ever modify a post that already exists.
 */
final class ProposeNewPost implements AbilityInterface {
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

    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-new-post',
            'label' => __('Propose New Post', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages creation of a brand new post or page for explicit admin approval. Use this — not '
                . 'awpt/propose-content-update — when there is no existing post to edit. Always creates as a '
                . 'draft; publishing is a separate admin decision.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => [
                        'type' => 'integer',
                        'description' => __('AWPT session ID.', 'agent-wordpress-terminal'),
                    ],
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional existing new-post proposal ID to revise in place. Use the ID from the open '
                            . 'proposals context when the user asks to change a staged post.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'turn_id' => [
                        'type' => 'string',
                        'description' => __(
                            'AWPT request identity. Supplied automatically.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'proposal_key' => [
                        'type' => 'string',
                        'description' => __(
                            'Stable key for this proposal within the turn; use a different key for a genuinely separate proposal.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'available_attachment_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => __(
                            'Composer attachments available as evidence. Supplied automatically; choose how to use them.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'available_document_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => __(
                            'Composer document attachments available as textual evidence. Supplied automatically.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'proposal_manifest' => [
                        'type' => 'object',
                        'properties' => [
                            'approach' => ['type' => 'string'],
                            'requirements' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'description' => __(
                            'Compact rationale: chosen approach, user requirements and how the draft satisfies them, plus assumptions.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'decision_trace' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Short ordered record of important discovery and composition decisions.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => __('Action card title.', 'agent-wordpress-terminal'),
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => __(
                            'Human-readable description of the proposed new post.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => __(
                            'Post title only (the WordPress title field). Do not also put this text in post_content.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_content' => [
                        'type' => 'string',
                        'description' => __(
                            'Post body only. For materialized pattern mode, optional supplemental Gutenberg blocks appended after the expanded pattern. For adapted/freeform mode, the complete body. Do not repeat post_title as a leading markdown # heading, HTML h1, or "Title:" line — themes already display the title above the content.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'content_replacements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'search' => ['type' => 'string'],
                                'replace' => ['type' => 'string'],
                                'expected_count' => ['type' => 'integer'],
                            ],
                            'required' => ['search', 'replace'],
                        ],
                        'description' => __(
                            'For a targeted revision with action_id, exact replacements to apply to the existing staged post_content. When supplied, AWPT ignores post_content and preserves every byte outside these replacements. Each search must match exactly once unless expected_count is provided.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_type' => [
                        'type' => 'string',
                        'description' => __(
                            'Post type to create: "post" or "page". Defaults to "post".',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'featured_image_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional Media Library attachment ID to set as the post featured image. '
                            . 'Use the ID from a pasted composer attachment when the user asks for a featured image.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_name' => [
                        'type' => 'string',
                        'description' => __('Optional URL slug for a new page or post.', 'agent-wordpress-terminal'),
                    ],
                    'post_parent' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional parent page ID. Only valid when post_type is page.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'page_template' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional active-theme page template slug, or default.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_name' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional registered or reusable pattern name. Named patterns default to materialized mode: AWPT expands the full pattern and applies compact replacements. Use adapted only when post_content intentionally contains the complete custom document. With prepend, AWPT inserts the unchanged pattern before a short tail.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_names' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Optional ordered registered-pattern composition. In materialized mode AWPT expands and concatenates every exact name before applying path-addressed edits. There is no document-size or section-count limit.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_mode' => [
                        'type' => 'string',
                        'enum' => ['materialized', 'prepend', 'adapted'],
                        'description' => __(
                            'materialized: AWPT expands the selected full-page pattern and applies compact pattern_replacements, with optional post_content appended. Preferred for ordinary and vague page requests. adapted: post_content is the complete custom composition and pattern_name is provenance. prepend: server inserts the unchanged pattern before a short tail.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_replacements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'search' => ['type' => 'string'],
                                'replace' => ['type' => 'string'],
                                'expected_count' => ['type' => 'integer'],
                            ],
                            'required' => ['search', 'replace'],
                        ],
                        'description' => __(
                            'Exact text or markup replacements applied to the recursively expanded registered pattern in materialized mode. Use these to customize headings, copy, buttons, and small markup without resending the full pattern.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_text_updates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'block_path' => ['type' => 'string'],
                                'content' => ['type' => 'string'],
                            ],
                            'required' => ['block_path', 'content'],
                        ],
                        'description' => __(
                            'Path-addressed rich-text updates applied to editable slots in a materialized pattern. Paths come from awpt/prepare-pattern-draft and preserve the pattern markup server-side.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'media_placements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'attachment_id' => ['type' => 'integer'],
                                'block_path' => ['type' => 'string'],
                                'position' => ['type' => 'string', 'enum' => ['before', 'after', 'append']],
                                'alt' => ['type' => 'string'],
                            ],
                            'required' => ['attachment_id', 'block_path', 'position'],
                        ],
                        'description' => __(
                            'Intentional Media Library image placements against original materialized-pattern paths.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_fallback_reason' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional short note when composing without a theme pattern. Pattern-first is preferred but not mandatory.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_unfit_code' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional telemetry when composing without a recommended theme pattern (no_recommendations, explicit_bespoke, preservation_conflict, media_unavailable, scope_mismatch). Not required to stage.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'required_attachment_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => __(
                            'Optional attachment requirements declared in the agent rationale; AWPT verifies them when supplied.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'required_document_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => __(
                            'Document attachment IDs used as source evidence. Documents are not forced into image blocks.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'required_minimum_library_images' => [
                        'type' => 'integer',
                        'description' => __(
                            'Exact minimum of distinct Media Library images explicitly requested by the user. Omit when the user gave no number.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'required_minimum_visuals' => [
                        'type' => 'integer',
                        'description' => __(
                            'Exact minimum of visible placements explicitly requested by the user. Omit when the user gave no number.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'required_links' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Optional destination links the agent declares must be present; verified when supplied.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'required_pattern_prefix' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional pattern namespace the agent declares must be present; verified when supplied.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['session_id', 'title', 'description', 'post_title'],
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
        unset($input);

        return current_user_can('edit_posts');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $revision_action_id = (int) ($input['action_id'] ?? 0);
        $turn_id = sanitize_key((string) ($input['turn_id'] ?? ''));
        $proposal_key = sanitize_key((string) ($input['proposal_key'] ?? ''));
        // Agents may pass action_id: 0 (or omit it) while intending a fresh proposal.
        // Only treat a positive integer as an explicit revise target.
        $explicit_revision = $revision_action_id > 0;

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(
                code: 'awpt_session_not_found',
                message: __('Session not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        if (!$explicit_revision && '' !== $turn_id && '' !== $proposal_key) {
            $idempotent_action = $this->actions->find_by_turn_key($session_id, $turn_id, $proposal_key);

            if (is_array($idempotent_action)) {
                $revision_action_id = (int) ($idempotent_action['id'] ?? 0);
            }
        }

        // Corrective turns often omit action_id. Auto-bind a single compatible open
        // new-post proposal (title/type match, or the only open new-post) so "add a
        // section" revises in place instead of spawning a duplicate card.
        if ($revision_action_id <= 0) {
            $revision_action_id = $this->actions->resolve_revisable_new_post_id(
                $session_id,
                sanitize_key((string) ($input['post_type'] ?? '')),
                (string) ($input['post_title'] ?? ''),
            );
        }

        $existing_payload = $this->revision_payload($revision_action_id, $session_id);

        if (is_wp_error($existing_payload)) {
            return $existing_payload;
        }

        $payload = $this->prepare_payload($input, $existing_payload);

        if (is_wp_error($payload)) {
            return $payload;
        }

        $title = sanitize_text_field((string) $input['title']);
        $description = sanitize_textarea_field((string) $input['description']);

        if ($revision_action_id > 0) {
            if (!$this->actions->revise($revision_action_id, $title, $description, $payload)) {
                $this->preview->prepare_new_post_payload($existing_payload);

                return new \WP_Error(
                    code: 'awpt_action_update_failed',
                    message: __('Could not revise the proposed action.', 'agent-wordpress-terminal'),
                    data: ['status' => 500],
                );
            }

            return $this->format_result($revision_action_id, 'revised');
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: $title,
            description: $description,
            payload: $payload,
            options: ['turn_id' => $turn_id, 'proposal_key' => $proposal_key],
        );

        if (null === $action_id) {
            $this->preview->discard_staging_draft($payload);

            return new \WP_Error(
                code: 'awpt_action_create_failed',
                message: __('Could not create proposed action.', 'agent-wordpress-terminal'),
                data: ['status' => 500],
            );
        }

        return $this->format_result($action_id, 'created');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing_payload
     * @return array<string, mixed>|\WP_Error
     */
    private function prepare_payload(array $input, array $existing_payload): array|\WP_Error {
        $post_title = trim(sanitize_text_field((string) ($input['post_title'] ?? '')));
        $post_content = $this->unwrap_content_transport((string) ($input['post_content'] ?? ''));
        $replacements = is_array($input['content_replacements'] ?? null) ? $input['content_replacements'] : [];
        $composition = new PatternCompositionPolicy();
        $requested_pattern_names = $this->pattern_names($input, $existing_payload);
        $requested_pattern_name = $requested_pattern_names[0] ?? sanitize_text_field(
            (string) ($input['pattern_name'] ?? $existing_payload['pattern_name'] ?? ''),
        );
        $pattern_mode = $composition->resolve_mode(
            (string) ($input['pattern_mode'] ?? ''),
            $requested_pattern_name,
            (string) ($existing_payload['pattern_mode'] ?? ''),
        );

        if ([] !== $replacements) {
            $existing_content = (string) ($existing_payload['post_content'] ?? '');

            if ('' === $existing_content) {
                return new \WP_Error(
                    'awpt_replacement_requires_revision',
                    __(
                        'Exact content replacements require an existing staged new-post proposal.',
                        'agent-wordpress-terminal',
                    ),
                    ['status' => 400],
                );
            }

            $post_content = $this->apply_content_replacements($existing_content, $replacements);

            if (is_wp_error($post_content)) {
                return $post_content;
            }
        } elseif (PatternCompositionPolicy::MODE_MATERIALIZED === $pattern_mode) {
            if ('' === $requested_pattern_name) {
                return new \WP_Error(
                    'awpt_materialized_pattern_required',
                    __('Materialized mode requires an exact registered pattern name.', 'agent-wordpress-terminal'),
                    ['status' => 400],
                );
            }

            $pattern_replacements = is_array($input['pattern_replacements'] ?? null)
                ? $input['pattern_replacements']
                : [];
            $text_updates = is_array($input['pattern_text_updates'] ?? null) ? $input['pattern_text_updates'] : [];
            $media_placements = is_array($input['media_placements'] ?? null) ? $input['media_placements'] : [];
            $materialized = new PatternCompositionBuilder()->build(
                $requested_pattern_names ?: [$requested_pattern_name],
                $pattern_replacements,
                $text_updates,
                $media_placements,
            );

            if (is_wp_error($materialized)) {
                return $materialized;
            }

            $supplemental = $post_content;
            $post_content = trim($materialized . ('' !== $supplemental ? "\n\n" . $supplemental : ''));
        }

        if ('' === $post_title || '' === $post_content) {
            return new \WP_Error(
                code: 'awpt_invalid_new_post',
                message: __('A post title and content are required to propose a new post.', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $post_type = sanitize_key((string) ($input['post_type'] ?? $existing_payload['post_type'] ?? 'post'));

        if (!in_array($post_type, ['post', 'page'], true)) {
            return new \WP_Error(
                code: 'awpt_invalid_post_type',
                message: __('Unsupported post type. Use "post" or "page".', 'agent-wordpress-terminal'),
                data: ['status' => 400],
            );
        }

        $featured_image_id = (int) ($input['featured_image_id'] ?? $existing_payload['featured_image_id'] ?? 0);

        if ($featured_image_id > 0) {
            $validation_error = $this->validate_featured_image($featured_image_id);

            if (null !== $validation_error) {
                return new \WP_Error(code: 'awpt_invalid_featured_image', message: $validation_error, data: [
                    'status' => 400,
                ]);
            }
        }

        $input_pattern_prefix = sanitize_text_field((string) ($input['required_pattern_prefix'] ?? ''));
        $required_pattern_prefix = '' !== $input_pattern_prefix
            ? $input_pattern_prefix
            : sanitize_text_field((string) ($existing_payload['required_pattern_prefix'] ?? ''));
        $pattern_name = $requested_pattern_names[0] ?? sanitize_text_field(
            (string) ($input['pattern_name'] ?? $existing_payload['pattern_name'] ?? ''),
        );
        $input_fallback_reason = sanitize_textarea_field((string) ($input['pattern_fallback_reason'] ?? ''));
        $pattern_fallback_reason = '' !== $input_fallback_reason
            ? $input_fallback_reason
            : sanitize_textarea_field((string) ($existing_payload['pattern_fallback_reason'] ?? ''));
        $input_unfit_code = sanitize_key((string) ($input['pattern_unfit_code'] ?? ''));
        $pattern_unfit_code = '' !== $input_unfit_code
            ? $input_unfit_code
            : sanitize_key((string) ($existing_payload['pattern_unfit_code'] ?? ''));
        $existing_attachment_ids = is_array($existing_payload['required_attachment_ids'] ?? null)
            ? $existing_payload['required_attachment_ids']
            : [];
        $input_attachment_ids = is_array($input['required_attachment_ids'] ?? null)
            ? $input['required_attachment_ids']
            : [];
        $available_attachment_ids = is_array($input['available_attachment_ids'] ?? null)
            ? $input['available_attachment_ids']
            : [];
        // Composer attachments are admin-selected evidence for this turn; require them
        // inline even if the model only set featured_image_id.
        $required_attachment_ids = $this->integer_list(array_merge(
            $existing_attachment_ids,
            $input_attachment_ids,
            $available_attachment_ids,
        ));
        $existing_document_ids = is_array($existing_payload['required_document_ids'] ?? null)
            ? $existing_payload['required_document_ids']
            : [];
        $input_document_ids = is_array($input['required_document_ids'] ?? null) ? $input['required_document_ids'] : [];
        $available_document_ids = is_array($input['available_document_ids'] ?? null)
            ? $input['available_document_ids']
            : [];
        $required_document_ids = $this->integer_list(array_merge(
            $existing_document_ids,
            $input_document_ids,
            $available_document_ids,
        ));
        $existing_links = is_array($existing_payload['required_links'] ?? null)
            ? $existing_payload['required_links']
            : [];
        $input_links = is_array($input['required_links'] ?? null) ? $input['required_links'] : [];
        $required_links = $this->url_list(array_key_exists('required_links', $input) ? $input_links : $existing_links);
        $required_minimum_library_images = max(0, min(
            20,
            (int) (
                $input['required_minimum_library_images'] ?? $existing_payload['required_minimum_library_images'] ?? 0
            ),
        ));
        $required_minimum_visuals = max(0, min(
            20,
            (int) ($input['required_minimum_visuals'] ?? $existing_payload['required_minimum_visuals'] ?? 0),
        ));

        if (!in_array(
            $pattern_mode,
            [
                PatternCompositionPolicy::MODE_PREPEND,
                PatternCompositionPolicy::MODE_ADAPTED,
                PatternCompositionPolicy::MODE_MATERIALIZED,
            ],
            true,
        )) {
            return new \WP_Error(
                'awpt_invalid_pattern_mode',
                __('Pattern mode must be materialized, prepend, or adapted.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $pattern_content = '';
        $pattern_summary = [];

        if ('' !== $pattern_name) {
            $pattern = $this->patterns->find($pattern_name);

            if (null === $pattern) {
                $validator = new PostCompositionValidator();

                return new \WP_Error(
                    'awpt_pattern_not_found',
                    __('The requested pattern is not available.', 'agent-wordpress-terminal'),
                    [
                        'status' => 404,
                        'requested_pattern' => $pattern_name,
                        'available_patterns' => $this->patterns->suggestions($pattern_name, 12, $post_type),
                        'validation_issues' => $validator->diagnose(
                            $post_content,
                            $required_attachment_ids,
                            $required_links,
                            $required_pattern_prefix,
                            [
                                'pattern_name' => $pattern_name,
                                'minimum_library_images' => $required_minimum_library_images,
                                'minimum_visuals' => $required_minimum_visuals,
                                'featured_image_id' => $featured_image_id,
                            ],
                        ),
                        'recommended_next_tools' => [
                            ['tool' => 'awpt/list-patterns', 'input' => ['search' => '', 'max' => 24]],
                            ['tool' => 'awpt/list-content', 'input' => ['post_type' => 'attachment', 'limit' => 12]],
                        ],
                        'recovery' => __(
                            'Choose an exact available pattern name, or inspect patterns and Media Library images before making the single corrected proposal. Address every validation issue together.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                );
            }

            $pattern_summary = $this->patterns->summary($pattern, $post_type);
            $pattern_content = trim((string) ($pattern['content'] ?? ''));

            if ('' === $pattern_content) {
                return new \WP_Error(
                    'awpt_empty_pattern',
                    __('The requested pattern has no usable block content.', 'agent-wordpress-terminal'),
                    ['status' => 400],
                );
            }

            $mode_conflict = $composition->conflict_if_prepend_would_duplicate(
                $pattern_mode,
                $pattern_content,
                $post_content,
            );

            if (null !== $mode_conflict) {
                return $mode_conflict;
            }

            if ($composition->should_prepend($pattern_mode, $pattern_content, $post_content)) {
                $post_content =
                    new PatternMaterializer()->materialize($pattern_name, $pattern_content) . "\n\n" . $post_content;
            }
        }

        $pattern_owner = '' !== $pattern_name ? (string) ($pattern_summary['owner'] ?? 'other') : 'custom';
        $pipeline = new PostContentStagingPipeline();
        $normalization = $pipeline->normalize($post_content);
        $post_content = $normalization['content'];
        $repairs_applied = $normalization['repairs'];

        $validator = new PostCompositionValidator();
        $syntax_error = $validator->validate_syntax($post_content);

        if (null !== $syntax_error) {
            return $syntax_error;
        }

        $media_integrity = new PostContentMediaIntegrity()->prepare($post_content);

        if (is_wp_error($media_integrity)) {
            return $media_integrity;
        }

        $post_content = $media_integrity['content'];
        $repairs_applied = [...$repairs_applied, ...$media_integrity['repairs']];

        if ('' !== $pattern_content) {
            $twin_conflict = $composition->conflict_if_raw_pattern_twin($pattern_content, $post_content);

            if (null !== $twin_conflict) {
                // Adapted mode: agents often re-paste registered pattern markup above a
                // filled layout (especially on revisions). Prefer the filled document.
                if (PatternCompositionPolicy::MODE_ADAPTED === $pattern_mode) {
                    $stripped = $composition->strip_raw_pattern_twin($pattern_content, $post_content);

                    if (is_string($stripped) && '' !== $stripped) {
                        $post_content = $stripped;
                        $repairs_applied[] = 'stripped_raw_pattern_twin';
                        $twin_conflict = null;
                    }
                }

                if (null !== $twin_conflict) {
                    return $twin_conflict;
                }
            }
        }

        // Adapted patterns are editable, but their declared dynamic or
        // structural dependencies are not optional. Restore exact source
        // structure only when the model omitted it entirely; freeform drafts
        // (no pattern_name) never enter this path.
        if ('' !== $pattern_content && PatternCompositionPolicy::MODE_ADAPTED === $pattern_mode) {
            $completion = new PatternStructureCompleter()->complete($pattern_name, $pattern_content, $post_content);
            $post_content = $completion['content'];
            $repairs_applied = [...$repairs_applied, ...$completion['repairs']];
        }

        $validation_error = $validator->validate(
            $post_content,
            $required_attachment_ids,
            $required_links,
            $required_pattern_prefix,
            [
                'pattern_name' => $pattern_name,
                'minimum_library_images' => $required_minimum_library_images,
                'minimum_visuals' => $required_minimum_visuals,
                'featured_image_id' => $featured_image_id,
            ],
        );

        if (null !== $validation_error) {
            $data = $validation_error->get_error_data();
            $data = is_array($data) ? $data : [];

            if (!array_key_exists('recovery', $data)) {
                $data['recovery'] = __(
                    'Fix the listed composition issues, then resubmit one awpt/propose-new-post with pattern_mode adapted and a single full composition when using a pattern. Reuse pattern markup already returned by awpt/read-pattern in this turn — do not re-list or re-read the same patterns unless switching pattern_name. Do not prepend a raw pattern under a filled layout.',
                    'agent-wordpress-terminal',
                );
            }

            return new \WP_Error($validation_error->get_error_code(), $validation_error->get_error_message(), $data);
        }

        $domain_validation = new CompositionGate();

        $materializer = new PatternMaterializer();

        if (
            '' !== $pattern_name
            && in_array(
                $pattern_mode,
                [PatternCompositionPolicy::MODE_ADAPTED, PatternCompositionPolicy::MODE_MATERIALIZED],
                true,
            )
            && !$materializer->has_provenance($pattern_name, $post_content)
        ) {
            $post_content = $materializer->materialize($pattern_name, $post_content, false);
        }

        $title_strategy = new ThemePostTitleStrategy();
        $requested_page_template = sanitize_text_field(
            (string) ($input['page_template'] ?? $existing_payload['page_template'] ?? ''),
        );

        if (
            ThemePostTitleStrategy::CONTENT_REQUIRED === $title_strategy->for_post_type(
                $post_type,
                $requested_page_template,
            )
            && !$title_strategy->content_has_h1($post_content)
        ) {
            return new \WP_Error(
                'awpt_content_h1_required',
                __(
                    'The active template does not render the WordPress post title. Add exactly one level-1 core/heading for the page headline, then keep section headings at level 2 or deeper.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'title_strategy' => ThemePostTitleStrategy::CONTENT_REQUIRED,
                    'recovery' => __(
                        'Change the main hero or page headline to a level-1 core/heading. Do not add a second H1.',
                        'agent-wordpress-terminal',
                    ),
                ],
            );
        }

        $domain_result = $domain_validation->evaluate(
            $post_content,
            [
                'operation' => ActionOperations::NEW_POST,
                'work_type' => 'compose',
                'post_type' => $post_type,
                'pattern_name' => $pattern_name,
                'phase' => 'stage',
            ],
            true,
        );
        $post_content = $domain_result['content'];
        $domain_findings = $domain_result['findings'];
        $domain_error = $domain_validation->blocking_error($domain_findings);

        if (null !== $domain_error) {
            $domain_error->add_data([
                'status' => 409,
                'validation_findings' => $domain_findings,
                'ruleset_hash' => $domain_result['ruleset_hash'],
                'safe_fixes' => $domain_result['fixes'],
                'agent_feedback' => $domain_result['agent_feedback'],
            ]);
            return $domain_error;
        }

        $payload = [
            'operation' => ActionOperations::NEW_POST,
            'post_id' => (int) ($existing_payload['post_id'] ?? 0),
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_title' => $post_title,
            'post_content' => PostContentSanitizer::for_staged_update($post_content),
            'ruleset_hash' => $domain_result['ruleset_hash'],
            'agent_feedback' => AgentFeedback::validation($domain_findings, $domain_result['fixes'], true),
        ];

        if ('' !== $pattern_name) {
            $payload['pattern_name'] = $pattern_name;
            $payload['pattern_mode'] = $pattern_mode;
            $payload['pattern_title'] = (string) ($pattern_summary['title'] ?? '');
            $payload['pattern_source'] = (string) ($pattern_summary['source'] ?? '');
            $payload['pattern_owner'] = $pattern_owner;
            $payload['composition_manifest'] = new PatternMaterializer()->provenance(
                $pattern_name,
                $pattern_mode,
                $pattern_content,
            );

            if (count($requested_pattern_names) > 1) {
                $payload['pattern_names'] = $requested_pattern_names;
                $payload['composition_manifest']['patterns'] = $this->composition_provenance(
                    $requested_pattern_names,
                    $pattern_mode,
                    $post_type,
                );
            }
        }

        if ([] !== $domain_findings) {
            $payload['validation_findings'] = $domain_findings;
        }

        if ([] !== $domain_result['fixes']) {
            $payload['safe_fixes'] = $domain_result['fixes'];
            $repairs_applied = [
                ...$repairs_applied,
                ...array_map(static fn(array $fix): string => (string) ($fix['id'] ?? ''), $domain_result['fixes']),
            ];
        }

        if ('' !== $pattern_fallback_reason) {
            $payload['pattern_fallback_reason'] = $pattern_fallback_reason;
        }
        if ('' !== $pattern_unfit_code) {
            $payload['pattern_unfit_code'] = $pattern_unfit_code;
        }

        if ([] !== $required_attachment_ids) {
            $payload['required_attachment_ids'] = $required_attachment_ids;
        }

        if ([] !== $required_document_ids) {
            $payload['required_document_ids'] = $required_document_ids;
        }

        if ($required_minimum_library_images > 0) {
            $payload['required_minimum_library_images'] = $required_minimum_library_images;
        }

        if ($required_minimum_visuals > 0) {
            $payload['required_minimum_visuals'] = $required_minimum_visuals;
        }

        if ([] !== $required_links) {
            $payload['required_links'] = $required_links;
        }

        if ('' !== $required_pattern_prefix) {
            $payload['required_pattern_prefix'] = $required_pattern_prefix;
        }

        if (is_array($input['proposal_manifest'] ?? null)) {
            $payload['proposal_manifest'] = $input['proposal_manifest'];
        }

        if (is_array($input['decision_trace'] ?? null)) {
            $payload['decision_trace'] = $input['decision_trace'];
        }

        if ([] !== $repairs_applied) {
            $payload['repairs_applied'] = $repairs_applied;
        }

        $post_name = sanitize_title((string) ($input['post_name'] ?? ''));

        if ('' !== $post_name) {
            $payload['post_name'] = $post_name;
        }

        $post_parent = (int) ($input['post_parent'] ?? 0);

        if ($post_parent > 0) {
            $parent = get_post($post_parent);

            if (
                'page' !== $post_type
                || !$parent instanceof \WP_Post
                || 'page' !== $parent->post_type
                || !current_user_can('edit_post', $post_parent)
            ) {
                return new \WP_Error(
                    'awpt_invalid_page_parent',
                    __('A page parent must be an editable existing page.', 'agent-wordpress-terminal'),
                    ['status' => 400],
                );
            }

            $payload['post_parent'] = $post_parent;
        }

        $page_template = sanitize_text_field((string) ($input['page_template'] ?? ''));

        if ('' !== $page_template && 'default' !== $page_template) {
            if ('page' !== $post_type || !array_key_exists($page_template, $this->available_page_templates())) {
                return new \WP_Error(
                    'awpt_invalid_page_template',
                    __('The requested page template is not available in the active theme.', 'agent-wordpress-terminal'),
                    ['status' => 400],
                );
            }

            $payload['page_template'] = $page_template;
        }

        if ($featured_image_id > 0) {
            $payload['featured_image_id'] = $featured_image_id;
        } elseif (array_key_exists('featured_image_id', $existing_payload)) {
            $payload['featured_image_id'] = (int) $existing_payload['featured_image_id'];
        }

        return $this->preview->prepare_new_post_payload($payload);
    }

    /**
     * Tool arguments are already JSON strings, but long-form models sometimes
     * add an XML CDATA or Markdown fence around the complete Gutenberg document.
     * Remove only a matching whole-value envelope; never rewrite inner markup.
     */
    private function unwrap_content_transport(string $content): string {
        return PostContentSanitizer::unwrap_transport($content);
    }

    /** @return array<string, string> Template filename keyed to display name. */
    private function available_page_templates(): array {
        if (function_exists('get_page_templates')) {
            return $this->normalize_page_templates(get_page_templates());
        }

        $theme = wp_get_theme();

        if (!method_exists($theme, 'get_page_templates')) {
            return [];
        }

        $templates = $theme->get_page_templates(null, 'page');

        return is_array($templates) ? $this->normalize_page_templates($templates) : [];
    }

    /**
     * @param array<array-key, mixed> $templates
     * @return array<string, string>
     */
    private function normalize_page_templates(array $templates): array {
        $normalized = [];

        foreach ($templates as $filename => $label) {
            if (!is_string($filename) || !is_string($label)) {
                continue;
            }

            $normalized[$filename] = $label;
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $replacements
     */
    private function apply_content_replacements(string $content, array $replacements): string|\WP_Error {
        foreach ($replacements as $index => $replacement) {
            if (!is_array($replacement)) {
                return new \WP_Error(
                    'awpt_invalid_content_replacement',
                    __('Content replacements must be objects.', 'agent-wordpress-terminal'),
                    ['status' => 400],
                );
            }

            $search = (string) ($replacement['search'] ?? '');
            $replace = (string) ($replacement['replace'] ?? '');
            $expected = max(1, (int) ($replacement['expected_count'] ?? 1));
            $actual = '' !== $search ? substr_count($content, $search) : 0;

            if ($actual !== $expected) {
                return new \WP_Error(
                    'awpt_content_replacement_mismatch',
                    sprintf(
                        __(
                            'Replacement %1$d expected %2$d exact match(es), but found %3$d.',
                            'agent-wordpress-terminal',
                        ),
                        (int) $index + 1,
                        $expected,
                        $actual,
                    ),
                    [
                        'status' => 409,
                        'replacement_index' => (int) $index,
                        'expected' => $expected,
                        'actual' => $actual,
                    ],
                );
            }

            $count = 0;
            $content = str_replace($search, $replace, $content, $count);

            if ($count !== $expected) {
                return new \WP_Error(
                    'awpt_content_replacement_failed',
                    __('Could not apply an exact content replacement.', 'agent-wordpress-terminal'),
                    ['status' => 409],
                );
            }
        }

        return $content;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function revision_payload(int $action_id, int $session_id): array|\WP_Error {
        if ($action_id <= 0) {
            return [];
        }

        $action = $this->actions->get_accessible_row($action_id);

        if (
            null === $action
            || $session_id !== (int) ($action['session_id'] ?? 0)
            || !in_array((string) ($action['status'] ?? ''), ['proposed', 'approved'], true)
        ) {
            return new \WP_Error(
                code: 'awpt_proposal_not_revisable',
                message: __(
                    'The staged new-post proposal is no longer available to revise.',
                    'agent-wordpress-terminal',
                ),
                data: ['status' => 409],
            );
        }

        $payload = $this->actions->decode_payload($action);

        if (ActionOperations::NEW_POST !== (string) ($payload['operation'] ?? '')) {
            return new \WP_Error(
                code: 'awpt_wrong_proposal_type',
                message: __(
                    'Only a staged new-post proposal can be revised with this ability.',
                    'agent-wordpress-terminal',
                ),
                data: ['status' => 400],
            );
        }

        return $payload;
    }

    private function validate_featured_image(int $attachment_id): ?string {
        $attachment = get_post($attachment_id);

        if (!$attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type) {
            return __('Featured image must be a valid Media Library attachment.', 'agent-wordpress-terminal');
        }

        if (!wp_attachment_is_image($attachment_id)) {
            return __('Featured image must be an image attachment.', 'agent-wordpress-terminal');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function format_result(int $action_id, string $revision_kind): array {
        $action = $this->actions->format_action($action_id);

        if (!is_array($action)) {
            return [];
        }

        $action['revision_kind'] = $revision_kind;
        $action['revised_action_id'] = $action_id;
        // Keep removed_action_ids from format_action (other open cards superseded this turn).
        if (!isset($action['removed_action_ids']) || !is_array($action['removed_action_ids'])) {
            $action['removed_action_ids'] = [];
        }

        return $action;
    }

    /**
     * Resolve the ordered server-side composition while preserving the legacy
     * single-pattern input. The array intentionally has no count limit: compact
     * authoring is a transport optimization, not a document-size policy.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing_payload
     * @return list<string>
     */
    private function pattern_names(array $input, array $existing_payload): array {
        $raw = array_key_exists('pattern_names', $input)
            ? $input['pattern_names']
            : $existing_payload['pattern_names'] ?? [];
        $names = [];

        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $name = sanitize_text_field((string) $value);

                if ('' !== $name && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        $primary = sanitize_text_field((string) ($input['pattern_name'] ?? $existing_payload['pattern_name'] ?? ''));

        if ('' !== $primary) {
            $names = array_values(array_filter($names, static fn(string $name): bool => $name !== $primary));
            array_unshift($names, $primary);
        }

        return $names;
    }

    /**
     * Describe every materialized pattern and its starting root path.
     *
     * @param list<string> $pattern_names
     * @return list<array<string, mixed>>
     */
    private function composition_provenance(array $pattern_names, string $mode, string $post_type): array {
        $manifest = [];
        $root_index = 0;
        $materializer = new PatternMaterializer();

        foreach ($pattern_names as $pattern_name) {
            $pattern = $this->patterns->find($pattern_name);

            if (null === $pattern) {
                continue;
            }

            $source = (string) ($pattern['content'] ?? '');
            $provenance = $materializer->provenance($pattern_name, $mode, $source);
            $raw_entry = $provenance['patterns'][0] ?? null;
            /** @var array<string, mixed> $entry */
            $entry = is_array($raw_entry) ? $raw_entry : [];
            $entry['block_path'] = (string) $root_index;
            $entry['title'] = (string) ($this->patterns->summary($pattern, $post_type)['title'] ?? '');
            $manifest[] = $entry;
            $expanded = new PatternTemplateExpander()->expand($pattern_name);

            if (!is_wp_error($expanded)) {
                $root_index += count(array_filter(
                    parse_blocks($expanded),
                    static fn(array $block): bool => null !== ($block['blockName'] ?? null),
                ));
            }
        }

        return $manifest;
    }

    /** @return list<int> */
    private function integer_list(mixed $values): array {
        if (!is_array($values)) {
            return [];
        }

        $items = array_map(static fn(mixed $value): int => absint(is_scalar($value) ? $value : 0), $values);

        return array_values(array_unique(array_filter($items, static fn(int $value): bool => $value > 0)));
    }

    /** @return list<string> */
    private function url_list(mixed $values): array {
        if (!is_array($values)) {
            return [];
        }

        $items = array_map(static fn(mixed $value): string => esc_url_raw(
            is_scalar($value) ? (string) $value : '',
        ), $values);

        return array_values(array_unique(array_filter($items)));
    }
}
