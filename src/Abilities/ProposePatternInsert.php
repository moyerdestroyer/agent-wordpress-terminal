<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Domain\CompositionGate;
use AWPT\Domain\ExistingContentPreservationValidator;
use AWPT\Domain\PatternEditableSlots;
use AWPT\Domain\PatternMaterializer;
use AWPT\Domain\PatternMediaPlacer;
use AWPT\Domain\PatternMediaSlots;
use AWPT\Domain\PatternPreparationReceipt;
use AWPT\Domain\PatternStructureEvidence;
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
                'Stages a prepared compact pattern insert, or an uncustomized registered pattern, for approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'post_id' => ['type' => 'integer'],
                    'pattern_name' => [
                        'type' => 'string',
                        'description' => 'Exact registered name for the uncustomized legacy path only. Omit after successful preparation.',
                    ],
                    'preparation_id' => [
                        'type' => 'string',
                        'description' => 'Copy the exact ID from prepare-pattern-change mode=insert. Required after preparation succeeds.',
                    ],
                    'pattern_text_updates' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'media_placements' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'block_path' => [
                        'type' => 'string',
                        'description' => 'Uncustomized legacy path only; prepared inserts use the receipt-bound target.',
                    ],
                    'position' => [
                        'type' => 'string',
                        'description' => 'Uncustomized legacy path only; prepared inserts use the receipt-bound position.',
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'post_id', 'title', 'description'],
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

        $preparation_id = sanitize_text_field((string) ($input['preparation_id'] ?? ''));
        $receipt = [];

        if ('' !== $preparation_id) {
            $loaded = new PatternPreparationReceipt()->require_for_propose($preparation_id, [
                'post_id' => $post_id,
                'session_id' => $session_id,
                'mode' => PatternPreparationReceipt::MODE_INSERT,
            ]);
            if (is_wp_error($loaded)) {
                return $loaded;
            }
            $receipt = $loaded;
            $source_hash = hash('sha256', $post->post_content);
            if (
                '' !== (string) ($receipt['source_content_hash'] ?? '')
                && !hash_equals((string) $receipt['source_content_hash'], $source_hash)
            ) {
                return new \WP_Error(
                    'awpt_preparation_source_stale',
                    __('The post content changed since insert preparation.', 'agent-wordpress-terminal'),
                    ['status' => 409, 'preparation_id' => $preparation_id],
                );
            }
            $path = sanitize_text_field((string) ($receipt['target_path'] ?? ''));
            $target = BlockTree::from_content($post->post_content)->get_block($path);
            $expected_fingerprint = sanitize_text_field((string) ($receipt['expected_fingerprint'] ?? ''));
            if (!is_array($target) || !hash_equals($expected_fingerprint, BlockTree::fingerprint($target))) {
                return new \WP_Error(
                    'awpt_block_fingerprint_mismatch',
                    __('The insert anchor changed since preparation.', 'agent-wordpress-terminal'),
                    ['status' => 409, 'preparation_id' => $preparation_id, 'target_path' => $path],
                );
            }
            $pattern_names = is_array($receipt['pattern_names'] ?? null) ? $receipt['pattern_names'] : [];
            $pattern_name = sanitize_text_field((string) ($pattern_names[0] ?? ''));
            $pattern_content = (string) ($receipt['pattern_content'] ?? '');
            if (
                '' === $pattern_name
                || '' === trim($pattern_content)
                || !hash_equals((string) ($receipt['expanded_content_hash'] ?? ''), hash('sha256', $pattern_content))
            ) {
                return new \WP_Error(
                    'awpt_preparation_pattern_stale',
                    __('Prepared insert pattern content failed integrity verification.', 'agent-wordpress-terminal'),
                    ['status' => 409, 'preparation_id' => $preparation_id],
                );
            }
            $updated = new PatternTextUpdater()->apply(
                $pattern_content,
                is_array($input['pattern_text_updates'] ?? null) ? $input['pattern_text_updates'] : [],
            );
            if (is_wp_error($updated)) {
                return $this->prepared_slot_error($updated, $receipt);
            }
            $placed = new PatternMediaPlacer()->apply(
                $updated,
                is_array($input['media_placements'] ?? null) ? $input['media_placements'] : [],
            );
            if (is_wp_error($placed)) {
                return $this->prepared_slot_error($placed, $receipt);
            }
            $pattern_content = $placed;
            $position = sanitize_key((string) ($receipt['position'] ?? BlockTree::POSITION_AFTER));
            $resolved = $this->patterns->resolve_name($pattern_name);
        } else {
            $resolved = $this->patterns->resolve_name((string) ($input['pattern_name'] ?? ''));
            if (null === $resolved) {
                return new \WP_Error('awpt_pattern_not_found', __('Pattern not found.', 'agent-wordpress-terminal'), [
                    'status' => 404,
                    'requested_name' => (string) ($input['pattern_name'] ?? ''),
                    'suggested_patterns' => $this->patterns->suggestions((string) ($input['pattern_name'] ?? ''), 8),
                ]);
            }
            $pattern = $resolved['pattern'];
            $pattern_content = (string) ($pattern['content'] ?? '');
            $pattern_name = $resolved['resolved_name'];
            $read_error = new PatternStructureEvidence()->require_read_for_pattern_name($session_id, $pattern_name);
            if ($read_error instanceof \WP_Error) {
                return $read_error;
            }
            $path = sanitize_text_field((string) ($input['block_path'] ?? ''));
            $position = sanitize_key((string) ($input['position'] ?? BlockTree::POSITION_APPEND));
        }

        $materializer = new PatternMaterializer();
        $materialized_content = $materializer->materialize($pattern_name, $pattern_content);
        $blocks = array_values(array_filter(parse_blocks($materialized_content), BlockTree::has_block_name(...)));
        $update = BlockTree::from_content($post->post_content)->insert_blocks($path, $blocks, $position);

        if (is_wp_error($update)) {
            return $update;
        }

        $summary = null !== $resolved
            ? $this->patterns->summary($resolved['pattern'], $post->post_type)
            : ['name' => $pattern_name, 'title' => $pattern_name, 'source' => '', 'owner' => ''];
        // Normalize before domain validation so repairable tagName/wrapper drift
        // does not reject an otherwise valid pattern insert.
        $pipeline = new PostContentStagingPipeline();
        $normalized = $pipeline->normalize($update['content']);
        $update['content'] = $normalized['content'];
        $repairs_applied = $normalized['repairs'];

        $validation = new CompositionGate();
        $validation_result = $validation->evaluate(
            $update['content'],
            [
                'operation' => ActionOperations::PATTERN_INSERT,
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
                'operation' => ActionOperations::PATTERN_INSERT,
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
            'preparation_id' => $preparation_id,
            'pattern_name' => $summary['name'],
            'pattern_title' => $summary['title'],
            'pattern_source' => $summary['source'],
            'pattern_owner' => $summary['owner'],
            'blocks' => $update['blocks'],
            'inserted_paths' => $update['paths'],
            'affected' => sprintf(__('Insert pattern %s', 'agent-wordpress-terminal'), (string) $summary['title']),
            'composition_manifest' => $materializer->provenance($pattern_name, 'inserted', $pattern_content),
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

        if ([] !== $media_integrity['repairs']) {
            $payload['repairs_applied'] = $media_integrity['repairs'];
        }

        $preservation_error = new ExistingContentPreservationValidator()->validate_for_session(
            $session_id,
            $post->post_content,
            $payload['post_content'],
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

    /** @param array<string, mixed> $receipt */
    private function prepared_slot_error(\WP_Error $error, array $receipt): \WP_Error {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];
        $content = (string) ($receipt['pattern_content'] ?? '');
        $data['preparation_id'] = (string) ($receipt['preparation_id'] ?? '');
        $data['editable_slots'] = new PatternEditableSlots()->from_content($content);
        $data['media_slots'] = new PatternMediaSlots()->from_content($content);
        $data['carry_forward'] = is_array($receipt['carry_forward'] ?? null) ? $receipt['carry_forward'] : [];
        $data['recovery'] = __(
            'Retry with this preparation_id and a block_path from editable_slots or media_slots. Do not prepare again.',
            'agent-wordpress-terminal',
        );
        $data['retry_example'] = [
            'preparation_id' => (string) ($receipt['preparation_id'] ?? ''),
            'pattern_text_updates' => [['block_path' => '0.0', 'content' => 'Replacement copy']],
        ];

        return new \WP_Error($error->get_error_code(), $error->get_error_message(), $data);
    }
}
