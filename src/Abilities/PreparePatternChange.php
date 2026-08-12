<?php

/**
 * awpt/prepare-pattern-change ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Domain\PatternEditableSlots;
use AWPT\Domain\PatternMediaSlots;
use AWPT\Domain\PatternPreparationReceipt;
use AWPT\Domain\PatternTemplateExpander;
use AWPT\Support\ArrayKey;
use AWPT\Support\BlockTree;
use AWPT\Support\ContentListService;
use AWPT\Support\PageSectionModel;

if (!defined('ABSPATH')) {
    exit();
}

/** Read-only preparation for section insert/replace on an existing post. */
final class PreparePatternChange implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/prepare-pattern-change',
            'label' => __('Prepare Pattern Change', 'agent-wordpress-terminal'),
            'description' => __(
                'Prepare a server-side pattern insert or replace on an existing post. Verifies the target block path and fingerprint, selects a compatible theme pattern, returns editable text/media slots, and mints a bound preparation_id for awpt/propose-pattern-replace (or insert). Do not resend pattern markup.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => __('Existing post or page to change.', 'agent-wordpress-terminal'),
                    ],
                    'intent' => [
                        'type' => 'string',
                        'description' => __(
                            'What the section should become, without inventing requirements.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['replace', 'insert'],
                        'description' => __(
                            'replace swaps the target section; insert places the pattern relative to the target path.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'target_path' => [
                        'type' => 'string',
                        'description' => __(
                            'Dotted block path for the section to replace, or the insert anchor (e.g. 0 or 2).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'expected_fingerprint' => [
                        'type' => 'string',
                        'description' => __(
                            'Fingerprint of the target block from read-block-tree. Optional: when omitted, the server uses the live fingerprint for the target_path.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'position' => [
                        'type' => 'string',
                        'enum' => ['before', 'after', 'append'],
                        'description' => __(
                            'Insert position when mode=insert. Defaults to after.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'media_count' => [
                        'type' => 'integer',
                        'description' => __(
                            'Recent Media Library image candidates to include.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['post_id', 'intent', 'mode', 'target_path'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_prepare'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_prepare(array $input): bool {
        $post_id = (int) ($input['post_id'] ?? 0);

        return $post_id > 0 && current_user_can('edit_post', $post_id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $post_id = (int) ($input['post_id'] ?? 0);
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $intent = sanitize_text_field((string) ($input['intent'] ?? ''));
        $mode = sanitize_key((string) ($input['mode'] ?? ''));
        $target_path = sanitize_text_field((string) ($input['target_path'] ?? ''));
        $expected_fingerprint = sanitize_text_field((string) ($input['expected_fingerprint'] ?? ''));
        $position = sanitize_key((string) ($input['position'] ?? BlockTree::POSITION_AFTER));
        $media_count = max(0, min(200, (int) ($input['media_count'] ?? 0)));
        $session_id = max(0, (int) ($input['session_id'] ?? 0));

        if ('' === $intent) {
            return new \WP_Error(
                'awpt_pattern_change_intent_required',
                __('An intent describing the section change is required.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        if (!in_array($mode, [PatternPreparationReceipt::MODE_REPLACE, PatternPreparationReceipt::MODE_INSERT], true)) {
            return new \WP_Error(
                'awpt_pattern_change_mode_invalid',
                __('mode must be replace or insert.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $tree = BlockTree::from_content($post->post_content);
        $section_menu = $this->top_level_section_menu($tree);
        $section_suggestions = PageSectionModel::suggest_for_intent($section_menu, $intent);
        $routing = PageSectionModel::recommend_operation($intent, null, $mode);

        if ('' === $target_path) {
            return new \WP_Error(
                'awpt_target_path_required',
                __(
                    'target_path is required. Pick a top-level section path from the menu (or call awpt/read-block-tree).',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'top_level_sections' => $section_menu,
                    'section_suggestions' => $section_suggestions,
                    'recommended_operation' => $routing,
                    'recovery' => __(
                        'Retry prepare-pattern-change with target_path (e.g. "0") and optional expected_fingerprint. Prefer a high-score section_suggestions path when intent names a role.',
                        'agent-wordpress-terminal',
                    ),
                ],
            );
        }

        if (1 !== preg_match('/^\d+(?:\.\d+)*$/', $target_path)) {
            return new \WP_Error(
                'awpt_invalid_block_path',
                __('target_path must be a dotted numeric path such as 0 or 2.1.', 'agent-wordpress-terminal'),
                [
                    'status' => 400,
                    'top_level_sections' => $section_menu,
                    'section_suggestions' => $section_suggestions,
                    'recommended_operation' => $routing,
                ],
            );
        }

        $target = $tree->get_block($target_path);

        if (null === $target) {
            return new \WP_Error(
                'awpt_block_not_found',
                __('Target block path was not found on the current post.', 'agent-wordpress-terminal'),
                [
                    'status' => 404,
                    'target_path' => $target_path,
                    'top_level_sections' => $section_menu,
                    'section_suggestions' => $section_suggestions,
                    'recommended_operation' => $routing,
                ],
            );
        }

        $live_fingerprint = BlockTree::fingerprint($target);
        $target_section = PageSectionModel::find_by_path($section_menu, $target_path) ?? $this->minimal_target_section(
            $target_path,
            $target,
            $live_fingerprint,
        );
        $routing = PageSectionModel::recommend_operation($intent, $target_section, $mode);
        $carry_forward = $this->carry_forward_from_section($target_section);
        $warnings = $this->section_warnings($intent, $mode, $target_section);
        $target_role = sanitize_key((string) ($target_section['role'] ?? ''));

        // Auto-fill fingerprint from live tree when the model omits it (one less hop).
        if ('' === $expected_fingerprint) {
            $expected_fingerprint = $live_fingerprint;
        } elseif (!hash_equals($expected_fingerprint, $live_fingerprint)) {
            return new \WP_Error(
                'awpt_block_fingerprint_mismatch',
                __('The target block changed since the provided fingerprint was captured.', 'agent-wordpress-terminal'),
                [
                    'status' => 409,
                    'target_path' => $target_path,
                    'received_fingerprint' => $expected_fingerprint,
                    'current_fingerprint' => $live_fingerprint,
                    'top_level_sections' => $section_menu,
                    'target_section' => $this->public_target_section($target_section),
                    'recommended_operation' => $routing,
                ],
            );
        }

        $ranked = new RecommendPatterns()->execute([
            'intent' => $intent,
            'post_type' => $post->post_type,
            'max' => 24,
            'semantic' => false,
            'target_role' => $target_role,
            'post_id' => $post_id,
            'target_path' => $target_path,
            'prefer_section_scope' => true,
        ]);
        $recommendations = ArrayKey::list_of_maps($ranked['recommendations'] ?? null);
        $selected = $this->first_section_pattern($recommendations, $target_role);

        if ([] === $selected) {
            return [
                'mode' => 'custom_fallback',
                'intent' => $intent,
                'post_id' => $post_id,
                'post_type' => $post->post_type,
                'target_path' => $target_path,
                'expected_fingerprint' => $live_fingerprint,
                'target_section' => $this->public_target_section($target_section),
                'carry_forward' => $carry_forward,
                'recommended_operation' => $routing,
                'warnings' => $warnings,
                'page_sections' => $section_menu,
                'reason' => __(
                    'No compatible section pattern was available for this change.',
                    'agent-wordpress-terminal',
                ),
                'fallback_code' => 'scope_mismatch',
                'media' => $this->media_candidates($media_count),
                'agent_feedback' => AgentFeedback::make(
                    'fallback',
                    __(
                        'No theme section pattern fit this change; use surgical tools or honest custom composition.',
                        'agent-wordpress-terminal',
                    ),
                    [
                        'next_actions' => [[
                            'ability' => 'awpt/propose-block-batch-update',
                            'reason' => __(
                                'Prefer surgical edits when no section pattern fits.',
                                'agent-wordpress-terminal',
                            ),
                        ]],
                    ],
                ),
            ];
        }

        $summary = ArrayKey::as_map($selected['pattern'] ?? null);
        $pattern_name = sanitize_text_field((string) ($summary['name'] ?? ''));
        $expanded = new PatternTemplateExpander()->expand($pattern_name);

        if (is_wp_error($expanded)) {
            return $expanded;
        }

        $content_hash = hash('sha256', $expanded);
        $source_hash = hash('sha256', $post->post_content);
        $minted = new PatternPreparationReceipt()->mint([
            'post_id' => $post_id,
            'session_id' => $session_id,
            'mode' => $mode,
            'intent' => $intent,
            'target_path' => $target_path,
            'expected_fingerprint' => $live_fingerprint,
            'source_content_hash' => $source_hash,
            'source_modified_gmt' => $post->post_modified_gmt,
            'pattern_names' => [$pattern_name],
            'expanded_content_hash' => $content_hash,
            'pattern_content' => $expanded,
            'position' => PatternPreparationReceipt::MODE_INSERT === $mode ? $position : '',
            'post_type' => $post->post_type,
            'carry_forward' => $carry_forward,
        ]);

        $propose_ability = PatternPreparationReceipt::MODE_REPLACE === $mode
            ? 'awpt/propose-pattern-replace'
            : 'awpt/propose-pattern-insert';

        return [
            'mode' => $mode,
            'preparation_id' => $minted['preparation_id'],
            'expires_at' => $minted['expires_at'],
            'intent' => $intent,
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'target_path' => $target_path,
            'expected_fingerprint' => $live_fingerprint,
            'position' => PatternPreparationReceipt::MODE_INSERT === $mode ? $position : null,
            'source_content_hash' => $source_hash,
            'target_section' => $this->public_target_section($target_section),
            'carry_forward' => $carry_forward,
            'recommended_operation' => $routing,
            'warnings' => $warnings,
            'page_sections' => $section_menu,
            'pattern' => [
                'name' => $pattern_name,
                'pattern_names' => [$pattern_name],
                'title' => (string) ($summary['title'] ?? ''),
                'owner' => (string) ($summary['owner'] ?? ''),
                'composition_scope' => (string) ($summary['composition_scope'] ?? ''),
                'content_hash' => $content_hash,
                'editable_slots' => new PatternEditableSlots()->from_content($expanded),
                'media_slots' => new PatternMediaSlots()->from_content($expanded),
            ],
            'selection' => [
                'score' => (int) ($selected['score'] ?? 0),
                'rationale' => (string) ($selected['rationale'] ?? ''),
            ],
            'media' => $this->media_candidates($media_count),
            'policy' => sprintf(
                /* translators: %s: propose ability name */
                __(
                    'Stage with %s using preparation_id and compact pattern_text_updates / media_placements. Do not serialize pattern markup. Unrelated sections are preserved server-side. Map carry_forward links/numbers into slots when relevant.',
                    'agent-wordpress-terminal',
                ),
                $propose_ability,
            ),
            'agent_feedback' => AgentFeedback::make(
                'ready',
                __('A bound pattern preparation is ready for a compact section change.', 'agent-wordpress-terminal'),
                [
                    'next_actions' => [[
                        'ability' => $propose_ability,
                        'reason' => __(
                            'Stage the prepared pattern change without resending markup.',
                            'agent-wordpress-terminal',
                        ),
                        'input' => [
                            'preparation_id' => $minted['preparation_id'],
                            'post_id' => $post_id,
                            'pattern_text_updates' => [],
                            'media_placements' => [],
                        ],
                    ]],
                ],
            ),
        ];
    }

    /**
     * Prefer non-layout patterns; when target_role is set, prefer matching role/scope first.
     *
     * @param list<array<string, mixed>> $recommendations
     * @return array<string, mixed>
     */
    private function first_section_pattern(array $recommendations, string $target_role = ''): array {
        $role_match = [];
        $section = [];
        $fallback = [];

        foreach ($recommendations as $recommendation) {
            $pattern = ArrayKey::as_map($recommendation['pattern'] ?? null);

            if ('incompatible' === (string) ($pattern['compatibility'] ?? '')) {
                continue;
            }

            $domain = ArrayKey::as_map($pattern['domain'] ?? null);
            $role = sanitize_key((string) ($domain['role'] ?? ''));
            $scope = sanitize_key((string) ($pattern['composition_scope'] ?? ''));
            $blob = mb_strtolower(
                $role
                . ' '
                . $scope
                . ' '
                . (string) ($pattern['name'] ?? '')
                . ' '
                . (string) ($pattern['title'] ?? ''),
                'UTF-8',
            );
            $is_layout = in_array($role, ['page-layout', 'page'], true) || in_array($scope, ['page', 'layout'], true);

            if ($is_layout) {
                if ([] === $fallback) {
                    $fallback = $recommendation;
                }

                continue;
            }

            if ('' !== $target_role && [] === $role_match) {
                foreach ($this->role_match_needles($target_role) as $needle) {
                    if (!('' !== $needle && str_contains($blob, $needle))) {
                        continue;
                    }

                    $role_match = $recommendation;
                    break;
                }
            }

            if ([] === $section) {
                $section = $recommendation;
            }
        }

        if ([] !== $role_match) {
            return $role_match;
        }

        if ([] !== $section) {
            return $section;
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function role_match_needles(string $target_role): array {
        return match ($target_role) {
            PageSectionModel::ROLE_HEADER => ['header', 'page-introduction', 'title', 'hero'],
            PageSectionModel::ROLE_HERO => ['hero', 'cover', 'banner', 'page-introduction'],
            PageSectionModel::ROLE_FAQ => ['faq', 'accordion', 'questions'],
            PageSectionModel::ROLE_CTA => ['cta', 'call-to-action', 'contact'],
            PageSectionModel::ROLE_STEPS => ['steps', 'process', 'how-to', 'howto'],
            PageSectionModel::ROLE_QUERY => ['query', 'news', 'listing', 'loop'],
            PageSectionModel::ROLE_MEDIA => ['media', 'gallery', 'image'],
            PageSectionModel::ROLE_BODY => ['body', 'content', 'section', 'about'],
            default => [$target_role],
        };
    }

    /**
     * Compact menu of top-level sections for prepare errors / discovery.
     *
     * @return list<array<string, mixed>>
     */
    private function top_level_section_menu(BlockTree $tree): array {
        return PageSectionModel::from_tree($tree);
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function minimal_target_section(string $path, array $target, string $fingerprint): array {
        $name = (string) ($target['blockName'] ?? $target['name'] ?? '');
        $markup = function_exists('serialize_block')
            ? new \AWPT\Support\BlockTreePathHelpers()->serialize([$target])
            : (string) ($target['innerHTML'] ?? '');
        $plain = trim(wp_strip_all_tags($markup));

        return [
            'path' => $path,
            'name' => $name,
            'block_name' => $name,
            'fingerprint' => $fingerprint,
            'role' => PageSectionModel::ROLE_UNKNOWN,
            'heading' => mb_substr($plain, 0, 80, 'UTF-8'),
            'heading_text' => mb_substr($plain, 0, 80, 'UTF-8'),
            'has_dynamic_blocks' => false,
            'preserve_by_default' => false,
            'links' => [],
            'numeric_tokens' => [],
            'excerpt' => mb_substr($plain, 0, 80, 'UTF-8'),
            'depth' => str_contains($path, '.') ? substr_count($path, '.') : 0,
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function public_target_section(array $section): array {
        return [
            'path' => (string) ($section['path'] ?? ''),
            'name' => (string) ($section['name'] ?? $section['block_name'] ?? ''),
            'fingerprint' => (string) ($section['fingerprint'] ?? ''),
            'role' => (string) ($section['role'] ?? PageSectionModel::ROLE_UNKNOWN),
            'heading' => (string) ($section['heading'] ?? ''),
            'has_dynamic_blocks' => (bool) ($section['has_dynamic_blocks'] ?? false),
            'preserve_by_default' => (bool) ($section['preserve_by_default'] ?? false),
            'excerpt' => (string) ($section['excerpt'] ?? ''),
        ];
    }

    /**
     * Links/numbers/heading from the target section for model slot filling (no PHP rewrite).
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function carry_forward_from_section(array $section): array {
        return [
            'links' => ArrayKey::list_of_strings($section['links'] ?? null),
            'numeric_tokens' => ArrayKey::list_of_strings($section['numeric_tokens'] ?? null),
            'heading' => (string) ($section['heading'] ?? ''),
            'excerpt' => (string) ($section['excerpt'] ?? ''),
            'note' => __(
                'Map these into pattern text/media slots when relevant. PHP does not invent or redistribute copy.',
                'agent-wordpress-terminal',
            ),
        ];
    }

    /**
     * Soft warnings only — never block freehand or replace solely on role.
     *
     * @param array<string, mixed> $section
     * @return list<string>
     */
    private function section_warnings(string $intent, string $mode, array $section): array {
        $warnings = [];
        $preserve = !empty($section['preserve_by_default']) || !empty($section['has_dynamic_blocks']);

        if ($preserve && PatternPreparationReceipt::MODE_REPLACE === $mode) {
            $explicit = (bool) preg_match(
                '/\b(replace|remove|swap|delete).{0,48}(query|loop|dynamic|posts?\s+list|comments?)\b|\bexplicit(ly)?\s+replace\b/i',
                $intent,
            );

            if (!$explicit) {
                $warnings[] = __(
                    'Target section has dynamic blocks (preserve_by_default). Prefer preserving query/loop content unless intent explicitly replaces it.',
                    'agent-wordpress-terminal',
                );
            }
        }

        return $warnings;
    }

    /** @return list<array<string, mixed>> */
    private function media_candidates(int $media_count): array {
        if ($media_count <= 0) {
            return [];
        }

        $limit = max($media_count, 4);
        $inventory = new ContentListService()->list([
            'post_type' => 'attachment',
            'status' => 'inherit',
            'orderby' => 'date',
            'order' => 'DESC',
            'limit' => $limit,
        ]);

        return array_slice(ArrayKey::list_of_maps($inventory['items'] ?? null), 0, $limit);
    }
}
