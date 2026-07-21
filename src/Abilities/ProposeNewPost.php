<?php

/**
 * awpt/propose-new-post ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\PatternCatalog;
use AWPT\Support\PatternCompositionPolicy;
use AWPT\Support\PostCompositionNormalizer;
use AWPT\Support\PostCompositionValidator;
use AWPT\Support\PostContentSanitizer;
use AWPT\Support\StagedPostPreview;

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
                            'Post body only. Do not repeat post_title as a leading markdown # heading, HTML h1, '
                            . 'or "Title:" line — themes already display the title above the content.',
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
                            'Optional registered or reusable pattern name for provenance. With pattern_mode adapted (default when named), post_content is the full customized document and the raw pattern is not injected. With pattern_mode prepend, the server inserts the unchanged pattern and post_content must be a short body-only tail. Read the pattern with awpt/read-pattern before adapted.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_mode' => [
                        'type' => 'string',
                        'enum' => ['prepend', 'adapted'],
                        'description' => __(
                            'adapted (default when pattern_name is set): post_content is the complete customized composition; pattern_name is provenance only. prepend: server inserts the unchanged pattern; pass only a short body tail in post_content — never a second filled layout.',
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
                    'required_minimum_library_images' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional agent-declared minimum of distinct inline Media Library images; verified when supplied.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'required_minimum_visuals' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional agent-declared minimum of visible image, cover, icon, or featured-image placements.',
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
                    'pattern_read_verified' => ['type' => 'boolean'],
                ],
                'required' => ['session_id', 'title', 'description', 'post_title', 'post_content'],
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
        $post_content = trim((string) ($input['post_content'] ?? ''));

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
        $input_pattern_name = sanitize_text_field((string) ($input['pattern_name'] ?? ''));
        $pattern_name = '' !== $input_pattern_name
            ? $input_pattern_name
            : sanitize_text_field((string) ($existing_payload['pattern_name'] ?? ''));
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

        $composition = new PatternCompositionPolicy();
        $pattern_mode = $composition->resolve_mode(
            (string) ($input['pattern_mode'] ?? ''),
            $pattern_name,
            (string) ($existing_payload['pattern_mode'] ?? ''),
        );

        if (!in_array(
            $pattern_mode,
            [
                PatternCompositionPolicy::MODE_PREPEND,
                PatternCompositionPolicy::MODE_ADAPTED,
            ],
            true,
        )) {
            return new \WP_Error(
                'awpt_invalid_pattern_mode',
                __('Pattern mode must be prepend or adapted.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $existing_pattern_name = sanitize_text_field((string) ($existing_payload['pattern_name'] ?? ''));
        // Revisions of an already-staged adapted pattern do not need another
        // read-pattern for the same name. Only require a fresh read when the
        // agent introduces or switches the pattern on this call.
        $pattern_unchanged_on_revision = '' !== $existing_pattern_name && $existing_pattern_name === $pattern_name;
        $pattern_read_required =
            PatternCompositionPolicy::MODE_ADAPTED === $pattern_mode
            && '' !== $pattern_name
            && array_key_exists('pattern_read_verified', $input)
            && !filter_var($input['pattern_read_verified'], FILTER_VALIDATE_BOOLEAN)
            && !$pattern_unchanged_on_revision;

        if ($pattern_read_required) {
            return new \WP_Error(
                'awpt_pattern_not_read',
                __(
                    'Read the selected pattern with awpt/read-pattern before staging an adapted composition.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'recovery' => __(
                        'Call awpt/read-pattern for the exact pattern name, then resubmit one awpt/propose-new-post with pattern_mode adapted and a single full customized composition in post_content.',
                        'agent-wordpress-terminal',
                    ),
                    'recommended_next_tools' => [
                        ['tool' => 'awpt/read-pattern', 'input' => ['name' => $pattern_name]],
                    ],
                ],
            );
        }

        $pattern_content = '';

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
                        'available_patterns' => $this->patterns->suggestions($pattern_name, 12),
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
                $post_content = $pattern_content . "\n\n" . $post_content;
            }
        }

        $validator = new PostCompositionValidator();
        $syntax_error = $validator->validate_syntax($post_content);

        if (null !== $syntax_error) {
            return $syntax_error;
        }

        $normalization = new PostCompositionNormalizer()->normalize($post_content);
        $post_content = $normalization['content'];
        $repairs_applied = $normalization['repairs'];

        // When required media is missing from post_content, insert an explicit
        // Image block near the top rather than failing after the model forgot it.
        if ([] !== $required_attachment_ids) {
            $media = $this->ensure_inline_attachments($post_content, $required_attachment_ids);
            $post_content = $media['content'];
            $repairs_applied = [...$repairs_applied, ...$media['repairs']];
        }

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
                    'Fix the listed composition issues, then resubmit one awpt/propose-new-post with pattern_mode adapted and a single full composition when using a pattern. Do not prepend a raw pattern under a filled layout.',
                    'agent-wordpress-terminal',
                );
            }

            return new \WP_Error($validation_error->get_error_code(), $validation_error->get_error_message(), $data);
        }

        $payload = [
            'operation' => ActionOperations::NEW_POST,
            'post_id' => (int) ($existing_payload['post_id'] ?? 0),
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_title' => $post_title,
            'post_content' => PostContentSanitizer::for_staged_update($post_content),
        ];

        if ('' !== $pattern_name) {
            $payload['pattern_name'] = $pattern_name;
            $payload['pattern_mode'] = $pattern_mode;
        }

        if ([] !== $required_attachment_ids) {
            $payload['required_attachment_ids'] = $required_attachment_ids;
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
            if ('page' !== $post_type || !array_key_exists($page_template, get_page_templates())) {
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

    /**
     * Prepend core/image blocks for required attachments missing from the composition.
     *
     * @param list<int> $attachment_ids
     * @return array{content: string, repairs: list<array{kind: string, block_path: string, block_name: string, description: string}>}
     */
    private function ensure_inline_attachments(string $content, array $attachment_ids): array {
        $repairs = [];
        $prefix = [];

        foreach ($attachment_ids as $attachment_id) {
            $attachment_id = absint($attachment_id);

            if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
                continue;
            }

            if ($this->content_includes_attachment($content, $attachment_id)) {
                continue;
            }

            $url = wp_get_attachment_image_url($attachment_id, 'large');

            if (!is_string($url) || '' === $url) {
                $url = (string) wp_get_attachment_url($attachment_id);
            }

            if ('' === $url) {
                continue;
            }

            $alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
            $prefix[] = sprintf(
                '<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->'
                . "\n"
                . '<figure class="wp-block-image size-large"><img src="%2$s" alt="%3$s" class="wp-image-%1$d"/></figure>'
                . "\n"
                . '<!-- /wp:image -->',
                $attachment_id,
                esc_url($url),
                esc_attr($alt),
            );
            $repairs[] = [
                'kind' => 'injected_required_image',
                'block_path' => '0',
                'block_name' => 'core/image',
                'description' => sprintf(
                    /* translators: %d: attachment ID. */
                    __(
                        'Inserted required Media Library image #%d near the start of the draft.',
                        'agent-wordpress-terminal',
                    ),
                    $attachment_id,
                ),
            ];
        }

        if ([] === $prefix) {
            return ['content' => $content, 'repairs' => []];
        }

        return [
            'content' => implode("\n\n", $prefix) . ('' !== trim($content) ? "\n\n" . ltrim($content) : ''),
            'repairs' => $repairs,
        ];
    }

    private function content_includes_attachment(string $content, int $attachment_id): bool {
        $id = (string) $attachment_id;

        return (
            str_contains($content, 'wp-image-' . $id)
            || str_contains($content, '"id":' . $id)
            || str_contains($content, '"id": ' . $id)
            || str_contains($content, '"mediaId":' . $id)
            || str_contains($content, '"mediaId": ' . $id)
        );
    }

    /** @return array<string, mixed> */
    private function format_result(int $action_id, string $revision_kind): array {
        $action = $this->actions->format_action($action_id);

        if (!is_array($action)) {
            return [];
        }

        $action['revision_kind'] = $revision_kind;
        $action['revised_action_id'] = $action_id;
        $action['removed_action_ids'] = [];

        return $action;
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
