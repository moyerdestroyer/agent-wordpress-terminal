<?php

/**
 * awpt/propose-pattern-replace ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Domain\CompositionGate;
use AWPT\Domain\ExistingContentPreservationValidator;
use AWPT\Domain\PatternCompositionBuilder;
use AWPT\Domain\PatternMaterializer;
use AWPT\Domain\PatternMediaPlacer;
use AWPT\Domain\PatternPreparationReceipt;
use AWPT\Domain\PatternTextUpdater;
use AWPT\Support\ActionOperations;
use AWPT\Support\BlockTree;
use AWPT\Support\PatternCatalog;
use AWPT\Support\PostContentMediaIntegrity;
use AWPT\Support\PostContentStagingPipeline;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/** Stages replacement of one verified section with a server-expanded pattern. */
final class ProposePatternReplace implements AbilityInterface {
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
            'name' => 'awpt/propose-pattern-replace',
            'label' => __('Propose Pattern Replace', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages replacement of one existing section with a registered pattern prepared by awpt/prepare-pattern-change. Supply preparation_id and compact text/media updates; AWPT expands the pattern, replaces only the verified target path, and preserves unrelated sections. Does not accept full-document freehand markup.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'preparation_id' => [
                        'type' => 'string',
                        'description' => __(
                            'Bound preparation_id returned by awpt/prepare-pattern-change (mode=replace).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => __('Must match the prepared post.', 'agent-wordpress-terminal'),
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
                    ],
                    'media_placements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'attachment_id' => ['type' => 'integer'],
                                'placement' => [
                                    'type' => 'string',
                                    'enum' => ['insert', 'featured_cover'],
                                ],
                                'block_path' => ['type' => 'string'],
                                'position' => [
                                    'type' => 'string',
                                    'enum' => ['before', 'after', 'append'],
                                ],
                                'alt' => ['type' => 'string'],
                            ],
                            'required' => ['attachment_id', 'block_path'],
                        ],
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'preparation_id', 'post_id', 'title', 'description'],
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

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $post_id = (int) ($input['post_id'] ?? 0);
        $preparation_id = sanitize_text_field((string) ($input['preparation_id'] ?? ''));
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

        $receipt = new PatternPreparationReceipt()->require_for_propose($preparation_id, [
            'post_id' => $post_id,
            'session_id' => $session_id,
            'mode' => PatternPreparationReceipt::MODE_REPLACE,
        ]);

        if (is_wp_error($receipt)) {
            return $receipt;
        }

        $source_hash = hash('sha256', $post->post_content);
        $expected_source = (string) ($receipt['source_content_hash'] ?? '');

        if ('' !== $expected_source && !hash_equals($expected_source, $source_hash)) {
            return new \WP_Error(
                'awpt_preparation_source_stale',
                __(
                    'The post content changed since preparation. Call awpt/prepare-pattern-change again.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409, 'preparation_id' => $preparation_id],
            );
        }

        $target_path = sanitize_text_field((string) ($receipt['target_path'] ?? ''));
        $expected_fingerprint = sanitize_text_field((string) ($receipt['expected_fingerprint'] ?? ''));
        $pattern_names = array_values(array_filter(array_map(
            static fn(mixed $name): string => sanitize_text_field(is_scalar($name) ? (string) $name : ''),
            is_array($receipt['pattern_names'] ?? null) ? $receipt['pattern_names'] : [],
        )));

        if ([] === $pattern_names) {
            return new \WP_Error(
                'awpt_preparation_patterns_missing',
                __('Preparation receipt has no pattern names.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        $text_updates = is_array($input['pattern_text_updates'] ?? null) ? $input['pattern_text_updates'] : [];
        $media_placements = is_array($input['media_placements'] ?? null) ? $input['media_placements'] : [];

        // Prefer the exact expanded markup bound at preparation so catalog drift
        // cannot silently rewrite a receipt-bound replace.
        $base = (string) ($receipt['pattern_content'] ?? '');

        if ('' === trim($base)) {
            $base = new PatternCompositionBuilder()->build($pattern_names);

            if (is_wp_error($base)) {
                return $base;
            }
        }

        $expected_expanded = (string) ($receipt['expanded_content_hash'] ?? '');

        if (
            '' !== $expected_expanded
            && !hash_equals($expected_expanded, hash('sha256', $base))
        ) {
            return new \WP_Error(
                'awpt_preparation_pattern_stale',
                __(
                    'Prepared pattern content no longer matches the receipt. Call awpt/prepare-pattern-change again.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409, 'preparation_id' => $preparation_id],
            );
        }

        $built = new PatternTextUpdater()->apply($base, $text_updates);

        if (is_wp_error($built)) {
            return $built;
        }

        $built = new PatternMediaPlacer()->apply($built, $media_placements);

        if (is_wp_error($built)) {
            return $built;
        }

        $pattern_name = $pattern_names[0];
        $materializer = new PatternMaterializer();
        $materialized_content = $materializer->materialize($pattern_name, $built);
        $blocks = array_values(array_filter(parse_blocks($materialized_content), BlockTree::has_block_name(...)));
        $update = BlockTree::from_content($post->post_content)->replace_blocks(
            $target_path,
            $blocks,
            $expected_fingerprint,
        );

        if (is_wp_error($update)) {
            return $update;
        }

        $resolved = $this->patterns->resolve_name($pattern_name);
        $summary = null !== $resolved
            ? $this->patterns->summary($resolved['pattern'], $post->post_type)
            : [
                'name' => $pattern_name,
                'title' => $pattern_name,
                'source' => '',
                'owner' => '',
            ];

        $pipeline = new PostContentStagingPipeline();
        $normalized = $pipeline->normalize($update['content']);
        $update['content'] = $normalized['content'];
        $repairs_applied = $normalized['repairs'];

        $validation = new CompositionGate();
        $validation_result = $validation->evaluate(
            $update['content'],
            [
                'operation' => ActionOperations::PATTERN_REPLACE,
                'work_type' => 'edit',
                'post_type' => $post->post_type,
                'pattern_name' => $pattern_name,
                'phase' => 'stage',
            ],
            true,
        );
        $update['content'] = $validation_result['content'];
        $findings = $validation_result['findings'];
        $baseline_result = $validation->evaluate(
            $post->post_content,
            [
                'operation' => ActionOperations::PATTERN_REPLACE,
                'work_type' => 'edit',
                'post_type' => $post->post_type,
                'pattern_name' => $pattern_name,
                'phase' => 'baseline',
            ],
            true,
        );
        $blocking_findings = \AWPT\Domain\CompositionProposalGuard::new_findings(
            $findings,
            $baseline_result['findings'],
        );
        $validation_error = $validation->blocking_error($blocking_findings);

        if (null !== $validation_error) {
            $validation_error->add_data([
                'status' => 409,
                'validation_findings' => $findings,
                'blocking_findings' => $blocking_findings,
                'ruleset_hash' => $validation_result['ruleset_hash'],
                'safe_fixes' => $validation_result['fixes'],
                'agent_feedback' => $validation_result['agent_feedback'],
                'recovery' => __(
                    'Fix only blocking_findings (newly introduced issues). Inherited import findings are grandfathered for this edit.',
                    'agent-wordpress-terminal',
                ),
            ]);

            return $validation_error;
        }

        $media_integrity = new PostContentMediaIntegrity()->prepare($update['content']);

        if (is_wp_error($media_integrity)) {
            return $media_integrity;
        }

        $update['content'] = $media_integrity['content'];
        $repairs_applied = [...$repairs_applied, ...$media_integrity['repairs']];

        $payload = [
            'operation' => ActionOperations::PATTERN_REPLACE,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'original_post_title' => $post->post_title,
            'original_post_content' => $post->post_content,
            'original_post_status' => $post->post_status,
            'post_content' => $update['content'],
            'block_path' => $target_path,
            'expected_fingerprint' => $expected_fingerprint,
            'preparation_id' => $preparation_id,
            'pattern_name' => (string) ($summary['name'] ?? $pattern_name),
            'pattern_title' => (string) ($summary['title'] ?? $pattern_name),
            'pattern_source' => (string) ($summary['source'] ?? ''),
            'pattern_owner' => (string) ($summary['owner'] ?? ''),
            'blocks' => $update['blocks'],
            'replaced_paths' => $update['paths'],
            'affected' => sprintf(
                __('Replace section %1$s with pattern %2$s', 'agent-wordpress-terminal'),
                $target_path,
                (string) ($summary['title'] ?? $pattern_name),
            ),
            'composition_manifest' => $materializer->provenance($pattern_name, 'replaced', $base),
            'ruleset_hash' => $validation_result['ruleset_hash'],
            'agent_feedback' => AgentFeedback::validation($findings, $validation_result['fixes'], true),
        ];

        if ([] !== $repairs_applied) {
            $payload['repairs_applied'] = $repairs_applied;
        }

        if ([] !== $findings) {
            $payload['validation_findings'] = $findings;
        }

        if ([] !== $validation_result['fixes']) {
            $payload['safe_fixes'] = $validation_result['fixes'];
        }

        $preservation_error = new ExistingContentPreservationValidator()->validate_for_session(
            $session_id,
            $post->post_content,
            (string) $payload['post_content'],
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
