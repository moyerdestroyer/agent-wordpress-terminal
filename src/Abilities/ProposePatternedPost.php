<?php

/**
 * awpt/propose-patterned-post ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Domain\PatternMediaPlacer;
use AWPT\Domain\PatternTextUpdater;
use AWPT\Support\ActionOperations;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/** Compact staging surface for ordinary pattern-led posts and pages. */
final class ProposePatternedPost implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-patterned-post',
            'label' => __('Propose Patterned Post', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a complete draft from the full-document pattern selected by awpt/prepare-pattern-draft. Supply path-addressed text updates and intentional Media Library placements; AWPT expands and serializes the pattern server-side. This compact path does not accept raw full-page markup. Use awpt/propose-new-post only for an explicitly custom/from-scratch composition or when preparation returned custom_fallback.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Existing staged new-post proposal ID for an in-place path-addressed revision.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'turn_id' => ['type' => 'string'],
                    'proposal_key' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'post_title' => ['type' => 'string'],
                    'post_type' => ['type' => 'string', 'enum' => ['post', 'page']],
                    'pattern_name' => [
                        'type' => 'string',
                        'description' => __(
                            'Exact pattern name returned by awpt/prepare-pattern-draft.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_names' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Ordered exact pattern names returned by preparation. The first is the page layout; any following section patterns are materialized and concatenated server-side without a page-size limit.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'pattern_text_updates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'block_path' => ['type' => 'string'],
                                'content' => [
                                    'type' => 'string',
                                    'description' => __(
                                        'Replacement text or safe inline HTML for this editable slot.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                            ],
                            'required' => ['block_path', 'content'],
                        ],
                        'description' => __(
                            'Text updates using editable slot paths returned by preparation or read-proposal revision_context. On a revision these may be partial; every unmentioned block is preserved server-side.',
                            'agent-wordpress-terminal',
                        ),
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
                                    'description' => __(
                                        'Use featured_cover for a Cover media slot returned by preparation; otherwise insert an Image block.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'block_path' => [
                                    'type' => 'string',
                                    'description' => __(
                                        'Original pattern block path used as the insertion anchor; empty only with append.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'position' => [
                                    'type' => 'string',
                                    'enum' => ['before', 'after', 'append'],
                                    'description' => __(
                                        'Required for insert placement and ignored for featured_cover.',
                                        'agent-wordpress-terminal',
                                    ),
                                ],
                                'alt' => ['type' => 'string'],
                            ],
                            'required' => ['attachment_id', 'block_path'],
                        ],
                        'description' => __(
                            'Intentional image assignments using Media Library IDs and semantic media-slot or insertion paths returned by preparation.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'featured_image_id' => ['type' => 'integer'],
                    'required_minimum_library_images' => ['type' => 'integer'],
                    'required_minimum_visuals' => ['type' => 'integer'],
                    'proposal_manifest' => ['type' => 'object', 'additionalProperties' => true],
                    'decision_trace' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => [
                    'session_id',
                    'title',
                    'description',
                    'post_title',
                    'post_type',
                    'pattern_text_updates',
                ],
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

    /** @param array<string, mixed> $input */
    public function can_propose(array $input): bool {
        unset($input);
        return current_user_can('edit_posts');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $placements = is_array($input['media_placements'] ?? null) ? $input['media_placements'] : [];

        if ((int) ($input['action_id'] ?? 0) > 0) {
            return $this->revise_existing($input, $placements);
        }

        $required_ids = [];
        $featured_placement_ids = [];

        foreach ($placements as $placement) {
            if (!is_array($placement)) {
                continue;
            }

            $raw_id = $placement['attachment_id'] ?? 0;
            $id = absint(is_scalar($raw_id) ? $raw_id : 0);

            if ($id > 0 && 'featured_cover' === sanitize_key((string) ($placement['placement'] ?? 'insert'))) {
                $featured_placement_ids[] = $id;
            } elseif ($id > 0) {
                $required_ids[] = $id;
            }
        }

        $raw_featured_id = $input['featured_image_id'] ?? 0;
        $featured_id = absint(is_scalar($raw_featured_id) ? $raw_featured_id : 0);
        $featured_placement_ids = array_values(array_unique($featured_placement_ids));

        if (
            count($featured_placement_ids) > 1
            || $featured_id > 0
            && [] !== $featured_placement_ids
            && !in_array($featured_id, $featured_placement_ids, true)
        ) {
            return new \WP_Error(
                'awpt_featured_cover_conflict',
                __('All featured Cover placements must use the post featured image.', 'agent-wordpress-terminal'),
                ['status' => 400, 'attachment_ids' => $featured_placement_ids],
            );
        }

        if (0 === $featured_id && [] !== $featured_placement_ids) {
            $featured_id = $featured_placement_ids[0];
        }
        $pattern_names = is_array($input['pattern_names'] ?? null) ? $input['pattern_names'] : [];
        $pattern_name = sanitize_text_field((string) ($input['pattern_name'] ?? ''));

        if ('' === $pattern_name) {
            foreach ($pattern_names as $candidate) {
                if (!is_scalar($candidate)) {
                    continue;
                }

                $pattern_name = sanitize_text_field((string) $candidate);

                if ('' !== $pattern_name) {
                    break;
                }
            }
        }

        if ('' === $pattern_name) {
            return new \WP_Error(
                'awpt_patterned_post_pattern_required',
                __(
                    'Use pattern_name or the first exact name in pattern_names from preparation.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 400],
            );
        }

        $delegate = array_merge($input, [
            'pattern_mode' => 'materialized',
            'pattern_name' => $pattern_name,
            'post_content' => '',
            'required_attachment_ids' => array_values(array_unique($required_ids)),
            'featured_image_id' => $featured_id,
        ]);

        return new ProposeNewPost()->execute($delegate);
    }

    /**
     * Apply compact path updates directly to the staged document. This avoids
     * asking the model to resend or reconstruct a large Gutenberg composition
     * for ordinary follow-up requests.
     *
     * @param array<string, mixed> $input
     * @param array<array-key, mixed> $placements
     * @return array<string, mixed>|\WP_Error
     */
    private function revise_existing(array $input, array $placements): array|\WP_Error {
        $action_id = (int) ($input['action_id'] ?? 0);
        $session_id = (int) ($input['session_id'] ?? 0);
        $action = new ActionRepository()->format_action($action_id);

        if (
            !is_array($action)
            || $session_id <= 0
            || $session_id !== (int) ($action['session_id'] ?? 0)
            || !in_array((string) ($action['status'] ?? ''), ['proposed', 'approved'], true)
        ) {
            return new \WP_Error(
                'awpt_patterned_revision_not_found',
                __('The staged proposal is unavailable for revision in this session.', 'agent-wordpress-terminal'),
                ['status' => 404],
            );
        }

        $payload = ArrayKey::string_map(is_array($action['payload'] ?? null) ? $action['payload'] : []);

        if (ActionOperations::NEW_POST !== (string) ($payload['operation'] ?? '')) {
            return new \WP_Error(
                'awpt_patterned_revision_wrong_operation',
                __('Path-addressed proposal revision requires a staged new post or page.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        $content = (string) ($payload['post_content'] ?? '');
        $updates = is_array($input['pattern_text_updates'] ?? null) ? $input['pattern_text_updates'] : [];
        $content = new PatternTextUpdater()->apply($content, $updates);

        if (is_wp_error($content)) {
            return $content;
        }

        $content = new PatternMediaPlacer()->apply($content, $placements);

        if (is_wp_error($content)) {
            return $content;
        }

        $placement_ids = [];
        $featured_placement_ids = [];

        foreach ($placements as $placement) {
            if (!is_array($placement)) {
                continue;
            }

            $raw_id = $placement['attachment_id'] ?? 0;
            $id = absint(is_scalar($raw_id) ? $raw_id : 0);

            if ($id > 0 && 'featured_cover' === sanitize_key((string) ($placement['placement'] ?? 'insert'))) {
                $featured_placement_ids[] = $id;
            } elseif ($id > 0) {
                $placement_ids[] = $id;
            }
        }

        $featured_placement_ids = array_values(array_unique($featured_placement_ids));
        $featured_image_id = (int) ($input['featured_image_id'] ?? $payload['featured_image_id'] ?? 0);

        if (
            count($featured_placement_ids) > 1
            || $featured_image_id > 0
            && [] !== $featured_placement_ids
            && !in_array($featured_image_id, $featured_placement_ids, true)
        ) {
            return new \WP_Error(
                'awpt_featured_cover_conflict',
                __('All featured Cover placements must use the post featured image.', 'agent-wordpress-terminal'),
                ['status' => 400, 'attachment_ids' => $featured_placement_ids],
            );
        }

        if (0 === $featured_image_id && [] !== $featured_placement_ids) {
            $featured_image_id = $featured_placement_ids[0];
        }

        $required_ids = array_values(array_unique(array_merge(
            $this->positive_ids($payload['required_attachment_ids'] ?? []),
            $placement_ids,
        )));
        $pattern_names = $this->existing_pattern_names($payload);
        $pattern_name = $pattern_names[0] ?? sanitize_text_field((string) ($payload['pattern_name'] ?? ''));
        $delegate = [
            'session_id' => $session_id,
            'action_id' => $action_id,
            'turn_id' => (string) ($input['turn_id'] ?? ''),
            'proposal_key' => (string) ($input['proposal_key'] ?? $action['proposal_key'] ?? 'primary'),
            'title' => (string) (
                $input['title'] ?? $action['title'] ?? __('Revise staged page', 'agent-wordpress-terminal')
            ),
            'description' => (string) ($input['description'] ?? $action['description'] ?? ''),
            'post_title' => (string) ($input['post_title'] ?? $payload['post_title'] ?? ''),
            'post_type' => (string) ($input['post_type'] ?? $payload['post_type'] ?? 'post'),
            'post_content' => $content,
            'pattern_mode' => 'adapted',
            'pattern_name' => $pattern_name,
            'pattern_names' => $pattern_names,
            'pattern_fallback_reason' => (string) ($payload['pattern_fallback_reason'] ?? ''),
            'featured_image_id' => $featured_image_id,
            'required_attachment_ids' => $required_ids,
            'required_document_ids' => $this->positive_ids($payload['required_document_ids'] ?? []),
            'required_minimum_library_images' => (int) ($payload['required_minimum_library_images'] ?? 0),
            'required_minimum_visuals' => (int) ($payload['required_minimum_visuals'] ?? 0),
            'required_links' => is_array($payload['required_links'] ?? null) ? $payload['required_links'] : [],
            'required_pattern_prefix' => (string) ($payload['required_pattern_prefix'] ?? ''),
            'post_name' => (string) ($payload['post_name'] ?? ''),
            'post_parent' => (int) ($payload['post_parent'] ?? 0),
            'page_template' => (string) ($payload['page_template'] ?? ''),
            'proposal_manifest' => is_array($input['proposal_manifest'] ?? null)
                ? $input['proposal_manifest']
                : (is_array($payload['proposal_manifest'] ?? null) ? $payload['proposal_manifest'] : []),
            'decision_trace' => is_array($input['decision_trace'] ?? null)
                ? $input['decision_trace']
                : (is_array($payload['decision_trace'] ?? null) ? $payload['decision_trace'] : []),
        ];

        return new ProposeNewPost()->execute($delegate);
    }

    /** @return list<int> */
    private function positive_ids(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn(mixed $id): int => absint(
            is_scalar($id) ? $id : 0,
        ), $value))));
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function existing_pattern_names(array $payload): array {
        $manifest = is_array($payload['composition_manifest'] ?? null) ? $payload['composition_manifest'] : [];
        $patterns = is_array($manifest['patterns'] ?? null) ? $manifest['patterns'] : [];
        $names = [];

        foreach ($patterns as $pattern) {
            if (!(is_array($pattern) && is_scalar($pattern['name'] ?? null))) {
                continue;
            }

            $name = sanitize_text_field((string) $pattern['name']);

            if ('' !== $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
