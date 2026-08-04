<?php

/**
 * awpt/propose-content-update ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AbilityReplacementRegistry;
use AWPT\Agent\AgentFeedback;
use AWPT\Database\ActionRepository;
use AWPT\Database\MessageRepository;
use AWPT\Database\SessionRepository;
use AWPT\Domain\CompositionProposalGuard;
use AWPT\Domain\DomainValidationService;
use AWPT\Domain\ExistingContentPreservationValidator;
use AWPT\Domain\PatternMaterializer;
use AWPT\Support\ActionOperations;
use AWPT\Support\NewPostStagingDraft;
use AWPT\Support\PatternCatalog;
use AWPT\Support\PatternFallbackPolicy;
use AWPT\Support\PostContentMediaIntegrity;
use AWPT\Support\PostContentSanitizer;
use AWPT\Support\SiteDesignContext;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates a staged content update action without saving the post.
 */
final class ProposeContentUpdate implements AbilityInterface {
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

    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-content-update',
            'label' => __('Propose Content Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a proposed post update (title, content, status, or meta) for explicit admin approval.',
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
                        'description' => __(
                            'Post ID to update. Prefer an explicit ID from read/search tools; session focus or an open content proposal may be used when omitted.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'presentation_requires_h1' => ['type' => 'boolean'],
                    'title' => [
                        'type' => 'string',
                        'description' => __(
                            'Action card title. Optional: AWPT defaults from the user request when omitted.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => __(
                            'Human-readable description of the proposed update. Optional: defaults from the user request.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_title' => [
                        'type' => 'string',
                        'description' => __('Optional replacement post title.', 'agent-wordpress-terminal'),
                    ],
                    'post_content' => [
                        'type' => 'string',
                        'description' => __('Optional replacement post content.', 'agent-wordpress-terminal'),
                    ],
                    'post_status' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional replacement post status (publish, draft, pending, private, future).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_meta' => [
                        'type' => 'object',
                        'description' => __(
                            'Optional post meta key/value pairs to update on approval.',
                            'agent-wordpress-terminal',
                        ),
                        'additionalProperties' => true,
                    ],
                    'affected' => [
                        'type' => 'string',
                        'description' => __('Affected block range or content area.', 'agent-wordpress-terminal'),
                    ],
                    'pattern_name' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional registered or reusable pattern used as provenance for a substantial layout rewrite. Read it before adapting.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_fallback_reason' => [
                        'type' => 'string',
                        'description' => __(
                            'For a substantial layout rewrite, explain why Core or custom composition is preferable when theme-native patterns are available.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_read_verified' => ['type' => 'boolean'],
                ],
                // session_id is injected by the runtime. title/description are filled when omitted.
                // post_id remains required for schema honesty, but the runtime also tries focus /
                // open proposals / "page 410" phrasing before validation fails.
                'required' => ['session_id'],
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

        if ($post_id <= 0) {
            $reader = new AbilityReplacementRegistry()->preferred('awpt/read-content');

            return new \WP_Error(
                code: 'awpt_missing_post_id',
                message: __(
                    sprintf(
                        'post_id is required. Pass the target page/post ID from %s or awpt/search-content.',
                        $reader,
                    ),
                    'agent-wordpress-terminal',
                ),
                data: [
                    'status' => 400,
                    'recommended_next_tools' => [
                        ['tool' => 'awpt/search-content', 'input' => ['query' => '']],
                        [
                            'tool' => $reader,
                            'input' => 'core/read-content' === $reader ? ['id' => 0, 'fields' => ['id']] : ['id' => 0],
                        ],
                    ],
                ],
            );
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error(code: 'awpt_post_not_found', message: __(
                'Post not found.',
                'agent-wordpress-terminal',
            ));
        }

        if (new NewPostStagingDraft()->is_staging_draft($post_id)) {
            return new \WP_Error(
                code: 'awpt_staging_draft_not_content',
                message: __(
                    'This post is a temporary new-post preview. Revise its staged proposal with '
                    . 'awpt/propose-new-post and the proposal action_id instead.',
                    'agent-wordpress-terminal',
                ),
                data: ['status' => 409],
            );
        }

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error(code: 'awpt_session_not_found', message: __(
                'Session not found.',
                'agent-wordpress-terminal',
            ));
        }

        $payload = [
            'operation' => ActionOperations::CONTENT_UPDATE,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'presentation_requires_h1' => true === ($input['presentation_requires_h1'] ?? false),
        ];

        if (array_key_exists('post_title', $input)) {
            $payload['post_title'] = sanitize_text_field((string) $input['post_title']);
        }

        if (array_key_exists('post_content', $input)) {
            $payload['post_content'] = PostContentSanitizer::for_staged_update((string) $input['post_content']);
        }

        $design_level = new SiteDesignContext()->request_level(new MessageRepository()->latest_user_message(
            $session_id,
        ));
        $substantial_design_update =
            array_key_exists('post_content', $input)
            && in_array($design_level, [SiteDesignContext::LEVEL_COMPOSITION, SiteDesignContext::LEVEL_SECTION], true);
        $pattern_name = sanitize_text_field((string) ($input['pattern_name'] ?? ''));
        $fallback_reason = sanitize_textarea_field((string) ($input['pattern_fallback_reason'] ?? ''));
        $pattern_owner = 'custom';

        if ('' !== $pattern_name) {
            $patterns = new PatternCatalog();
            $pattern = $patterns->find($pattern_name);

            if (null === $pattern) {
                return new \WP_Error(
                    'awpt_pattern_not_found',
                    __('The requested pattern is not available.', 'agent-wordpress-terminal'),
                    ['status' => 404],
                );
            }

            if (
                array_key_exists('pattern_read_verified', $input)
                && !filter_var($input['pattern_read_verified'], FILTER_VALIDATE_BOOLEAN)
            ) {
                return new \WP_Error(
                    'awpt_pattern_not_read',
                    __(
                        'Read the selected pattern before using it as the basis for a layout rewrite.',
                        'agent-wordpress-terminal',
                    ),
                    [
                        'status' => 400,
                        'recommended_next_tools' => [
                            ['tool' => 'awpt/read-pattern', 'input' => ['name' => $pattern_name]],
                        ],
                    ],
                );
            }

            $summary = $patterns->summary($pattern, $post->post_type);
            $pattern_owner = (string) ($summary['owner'] ?? 'other');
            $payload['pattern_name'] = $pattern_name;
            $payload['pattern_mode'] = 'adapted';
            $payload['pattern_title'] = (string) ($summary['title'] ?? '');
            $payload['pattern_source'] = (string) ($summary['source'] ?? '');
            $payload['pattern_owner'] = $pattern_owner;
        }

        if ($substantial_design_update) {
            $fallback_error = new PatternFallbackPolicy()->validate(
                new PatternCatalog(),
                $post->post_type,
                $pattern_owner,
                $fallback_reason,
            );

            if (null !== $fallback_error) {
                return $fallback_error;
            }
        }

        if ('' !== $fallback_reason) {
            $payload['pattern_fallback_reason'] = $fallback_reason;
        }

        if (array_key_exists('post_content', $payload)) {
            if ('' !== $pattern_name) {
                $payload['post_content'] = new PatternMaterializer()->materialize(
                    $pattern_name,
                    (string) $payload['post_content'],
                    false,
                );
                $payload['composition_manifest'] = new PatternMaterializer()->provenance(
                    $pattern_name,
                    'adapted',
                    (string) ($pattern['content'] ?? ''),
                );
            }

            $domain_validation = new DomainValidationService();
            $domain_result = $domain_validation->evaluate(
                (string) $payload['post_content'],
                [
                    'operation' => ActionOperations::CONTENT_UPDATE,
                    'work_type' => 'edit',
                    'post_type' => $post->post_type,
                    'pattern_name' => $pattern_name,
                    'phase' => 'stage',
                ],
                true,
            );
            $payload['post_content'] = $domain_result['content'];
            $domain_findings = $domain_result['findings'];
            $baseline_result = $domain_validation->evaluate(
                $post->post_content,
                [
                    'operation' => ActionOperations::CONTENT_UPDATE,
                    'work_type' => 'edit',
                    'post_type' => $post->post_type,
                    'pattern_name' => $pattern_name,
                    'phase' => 'baseline',
                ],
                true,
            );
            $domain_error = $domain_validation->blocking_error(CompositionProposalGuard::new_findings(
                $domain_findings,
                $baseline_result['findings'],
            ));

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

            $payload['ruleset_hash'] = $domain_result['ruleset_hash'];
            $payload['agent_feedback'] = AgentFeedback::validation($domain_findings, $domain_result['fixes'], true);

            if ([] !== $domain_findings) {
                $payload['validation_findings'] = $domain_findings;
            }

            if ([] !== $domain_result['fixes']) {
                $payload['safe_fixes'] = $domain_result['fixes'];
            }

            $media_integrity = new PostContentMediaIntegrity()->prepare((string) $payload['post_content']);

            if (is_wp_error($media_integrity)) {
                return $media_integrity;
            }

            $payload['post_content'] = $media_integrity['content'];

            if ([] !== $media_integrity['repairs']) {
                $payload['repairs_applied'] = $media_integrity['repairs'];
            }
        }

        if (array_key_exists('post_status', $input)) {
            $status = sanitize_key((string) $input['post_status']);

            if (!in_array($status, array_keys(get_post_statuses()), true)) {
                return new \WP_Error(code: 'awpt_invalid_post_status', message: __(
                    'Unsupported post status.',
                    'agent-wordpress-terminal',
                ));
            }

            $payload['post_status'] = $status;
        }

        if (array_key_exists('post_meta', $input) && is_array($input['post_meta'])) {
            $meta_changes = [];
            $original_meta = [];

            foreach ($input['post_meta'] as $key => $value) {
                $meta_key = sanitize_key((string) $key);

                if ('' === $meta_key) {
                    continue;
                }

                $meta_changes[$meta_key] = $this->sanitize_meta_value($value);
                $original_meta[$meta_key] = get_post_meta($post_id, $meta_key, true);
            }

            if ([] !== $meta_changes) {
                $payload['post_meta'] = $meta_changes;
                $payload['original_post_meta'] = $original_meta;
            }
        }

        if (array_key_exists('affected', $input)) {
            $payload['affected'] = sanitize_textarea_field((string) $input['affected']);
        }

        if ($this->missing_required_content_h1($payload)) {
            return new \WP_Error(
                'awpt_required_page_h1_missing',
                __(
                    'Rendered evidence shows this page needs a content H1; changing post metadata alone will not display one.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 409,
                    'recommended_next_tools' => [[
                        'tool' => 'awpt/propose-block-batch-update',
                        'reason' => 'Promote or insert one verified content heading at level 1.',
                    ]],
                ],
            );
        }

        if (!$this->has_effective_mutation($payload)) {
            return new \WP_Error(
                'awpt_content_update_no_changes',
                __(
                    'A content update must change the post title, content, status, or at least one meta value.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'recommended_next_tools' => [[
                        'tool' => 'awpt/propose-block-batch-update',
                        'reason' => 'Use verified block paths and fingerprints for presentation-only page changes.',
                    ]],
                ],
            );
        }

        $preservation_error = new ExistingContentPreservationValidator()->validate_for_session(
            $session_id,
            $post->post_content,
            (string) ($payload['post_content'] ?? $post->post_content),
        );

        if ($preservation_error instanceof \WP_Error) {
            return $preservation_error;
        }

        $preview = $this->preview->preview_from_payload($payload);

        if (is_wp_error($preview)) {
            return $preview;
        }

        $payload['preview_url'] = $preview['preview_url'];

        if (array_key_exists('autosave_id', $preview)) {
            $payload['preview_autosave_id'] = (int) $preview['autosave_id'];
        }

        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ('' === $title) {
            $title = sprintf(
                /* translators: %s: post title. */
                __('Update “%s”', 'agent-wordpress-terminal'),
                get_the_title($post),
            );
        }

        if ('' === $description) {
            $description = $title;
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field($title),
            description: sanitize_textarea_field($description),
            payload: $payload,
        );

        if (null === $action_id) {
            $this->preview->discard_preview_resources($payload);

            return new \WP_Error(code: 'awpt_action_create_failed', message: __(
                'Could not create proposed action.',
                'agent-wordpress-terminal',
            ));
        }

        $action = $this->actions->format_action($action_id);

        return is_array($action) ? $action : [];
    }

    /** @param array<string, mixed> $payload */
    private function has_effective_mutation(array $payload): bool {
        if (
            array_key_exists('post_title', $payload)
            && (string) $payload['post_title'] !== (string) ($payload['original_post_title'] ?? '')
        ) {
            return true;
        }

        if (
            array_key_exists('post_content', $payload)
            && (string) $payload['post_content'] !== (string) ($payload['original_post_content'] ?? '')
        ) {
            return true;
        }

        if (
            array_key_exists('post_status', $payload)
            && (string) $payload['post_status'] !== (string) ($payload['original_post_status'] ?? '')
        ) {
            return true;
        }

        $meta = is_array($payload['post_meta'] ?? null) ? $payload['post_meta'] : [];
        $original_meta = is_array($payload['original_post_meta'] ?? null) ? $payload['original_post_meta'] : [];

        foreach ($meta as $key => $value) {
            if (!array_key_exists($key, $original_meta) || $value !== $original_meta[$key]) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function missing_required_content_h1(array $payload): bool {
        return true === ($payload['presentation_requires_h1'] ?? false) && !array_key_exists('post_content', $payload);
    }

    private function sanitize_meta_value(mixed $value): string|int|float|bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }
}
