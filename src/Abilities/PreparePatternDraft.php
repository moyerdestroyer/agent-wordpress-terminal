<?php

/**
 * awpt/prepare-pattern-draft ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Domain\PatternEditableSlots;
use AWPT\Domain\PatternMediaSlots;
use AWPT\Support\ArrayKey;
use AWPT\Support\ContentListService;
use AWPT\Support\ThemePostTitleStrategy;

if (!defined('ABSPATH')) {
    exit();
}

/** Resolves a usable ordered pattern composition and its compact editing surface in one read. */
final class PreparePatternDraft implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/prepare-pattern-draft',
            'label' => __('Prepare Pattern Draft', 'agent-wordpress-terminal'),
            'description' => __(
                'Preferred first step for an ordinary new page or post. In one read, selects the best compatible full-document theme pattern plus relevant supporting sections, expands the ordered composition, returns path-addressed editable text slots, and includes recent Media Library images when requested. Use the result with awpt/propose-patterned-post. Requests explicitly requiring a from-scratch or bespoke layout return custom_fallback instead.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => [
                        'type' => 'string',
                        'description' => __(
                            'The user request, summarized without adding requirements.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'post_type' => [
                        'type' => 'string',
                        'enum' => ['post', 'page'],
                        'description' => __(
                            'Requested content type. Defaults to page when the user says page.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'media_count' => [
                        'type' => 'integer',
                        'description' => __(
                            'Number of recent Media Library image candidates needed; zero when images were not requested.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['intent'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_posts'),
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $intent = sanitize_text_field((string) ($input['intent'] ?? ''));
        $post_type = sanitize_key((string) ($input['post_type'] ?? 'page'));
        $media_count = max(0, min(200, (int) ($input['media_count'] ?? 0)));

        if ('' === $intent) {
            return new \WP_Error(
                'awpt_pattern_draft_intent_required',
                __('A page or post intent is required.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        if (!in_array($post_type, ['post', 'page'], true)) {
            $post_type = str_contains(mb_strtolower($intent), 'page') ? 'page' : 'post';
        }

        if ($this->explicit_custom_request($intent)) {
            return $this->custom_fallback(
                $intent,
                $post_type,
                __('The user explicitly requested a bespoke or from-scratch composition.', 'agent-wordpress-terminal'),
                $this->media_candidates($media_count),
            );
        }

        $ranked = new RecommendPatterns()->execute([
            'intent' => $intent,
            'post_type' => $post_type,
            'max' => 24,
            // Preparation must remain a deterministic local operation. The
            // provider will make the creative decisions in the next call.
            'semantic' => false,
        ]);
        $recommendations = ArrayKey::list_of_maps($ranked['recommendations'] ?? null);
        $selected = $this->first_full_document_pattern($recommendations);

        if ([] === $selected) {
            return $this->custom_fallback(
                $intent,
                $post_type,
                __('No compatible full-document pattern was available.', 'agent-wordpress-terminal'),
                $this->media_candidates($media_count),
            );
        }

        $composition = [$selected];
        $selected_names = [(string) (ArrayKey::as_map($selected['pattern'] ?? null)['name'] ?? '')];

        foreach ($this->supporting_requirements($intent, $media_count) as $requirement) {
            $support_ranked = new RecommendPatterns()->execute([
                'intent' => $requirement,
                'post_type' => $post_type,
                'max' => 24,
                'semantic' => false,
            ]);
            $support = $this->first_relevant_section_pattern(
                ArrayKey::list_of_maps($support_ranked['recommendations'] ?? null),
                $selected_names,
            );

            if ([] === $support) {
                continue;
            }

            $support_name = (string) (ArrayKey::as_map($support['pattern'] ?? null)['name'] ?? '');
            $composition[] = $support;
            $selected_names[] = $support_name;
        }

        $expanded_parts = [];
        $components = [];

        foreach ($composition as $index => $recommendation) {
            $summary = ArrayKey::as_map($recommendation['pattern'] ?? null);
            $name = (string) ($summary['name'] ?? '');
            $read = new ReadPattern()->execute([
                'name' => $name,
                'purpose' => 0 === $index
                    ? __('Prepare the selected full-document pattern.', 'agent-wordpress-terminal')
                    : __(
                        'Prepare a supporting section selected for an explicit content requirement.',
                        'agent-wordpress-terminal',
                    ),
            ]);

            if (is_wp_error($read)) {
                if (0 === $index) {
                    return $read;
                }

                continue;
            }

            $expanded_parts[] = (string) ($read['content'] ?? '');
            $domain = ArrayKey::as_map($summary['domain'] ?? null);
            $components[] = [
                'name' => $name,
                'title' => (string) ($read['title'] ?? ''),
                'owner' => (string) ($read['owner'] ?? ''),
                'role' => (string) ($domain['role'] ?? ''),
                'composition_scope' => (string) ($read['composition_scope'] ?? ''),
                'content_hash' => (string) ($read['content_hash'] ?? ''),
                'selection_reason' => (string) ($recommendation['rationale'] ?? ''),
            ];
        }

        $pattern_names = array_values(array_map(
            static fn(array $component): string => $component['name'],
            $components,
        ));
        $pattern_name = $pattern_names[0] ?? '';
        $expanded_content = implode("\n\n", $expanded_parts);
        $primary = $components[0] ?? [];
        $media = $this->media_candidates($media_count);

        return [
            'mode' => 'pattern',
            'intent' => $intent,
            'post_type' => $post_type,
            'title_strategy' => new ThemePostTitleStrategy()->for_post_type($post_type),
            'pattern' => [
                'name' => $pattern_name,
                'pattern_names' => $pattern_names,
                'title' => $primary['title'] ?? '',
                'owner' => $primary['owner'] ?? '',
                'composition_scope' => $primary['composition_scope'] ?? '',
                'content_hash' => hash('sha256', $expanded_content),
                'components' => $components,
                'editable_slots' => new PatternEditableSlots()->from_content($expanded_content),
                'media_slots' => new PatternMediaSlots()->from_content($expanded_content),
            ],
            'media' => $media,
            'selection' => [
                'score' => (int) ($selected['score'] ?? 0),
                'rationale' => (string) ($selected['rationale'] ?? ''),
                'supporting_requirements' => $this->supporting_requirements($intent, $media_count),
            ],
            'policy' => 'Stage with awpt/propose-patterned-post using the ordered pattern_names, pattern_text_updates, and explicit media_placements. Prefer returned semantic media_slots over inserting images near text. Use featured_cover for a hero Cover background and ordinary insert placement for additional images. Do not serialize or resend pattern markup. The ordered composition is not subject to a page-size or section-count limit.',
            'agent_feedback' => AgentFeedback::make(
                'ready',
                __(
                    'An ordered theme-pattern composition and its compact editing surface are ready.',
                    'agent-wordpress-terminal',
                ),
                [
                    'next_actions' => [[
                        'ability' => 'awpt/propose-patterned-post',
                        'reason' => __(
                            'Stage the requested draft without serializing the pattern.',
                            'agent-wordpress-terminal',
                        ),
                        'input' => [
                            'pattern_name' => $pattern_name,
                            'pattern_names' => $pattern_names,
                            'post_type' => $post_type,
                        ],
                    ]],
                ],
            ),
        ];
    }

    /** @param list<array<string, mixed>> $recommendations @return array<string, mixed> */
    private function first_full_document_pattern(array $recommendations): array {
        foreach ($recommendations as $recommendation) {
            $pattern = ArrayKey::as_map($recommendation['pattern'] ?? null);
            $domain = ArrayKey::as_map($pattern['domain'] ?? null);
            $scope = sanitize_key((string) ($pattern['composition_scope'] ?? ''));
            $role = sanitize_key((string) ($domain['role'] ?? ''));

            if ('incompatible' === (string) ($pattern['compatibility'] ?? '')) {
                continue;
            }

            if ('page-layout' === $role || '' === $role && in_array($scope, ['page', 'layout'], true)) {
                return $recommendation;
            }
        }

        return [];
    }

    /**
     * Convert ordinary language into independent structural requirements. This
     * is intentionally subject-agnostic: Commander, a city service, or a product
     * page use the same comparison/explanation/feature vocabulary.
     *
     * @return list<string>
     */
    private function supporting_requirements(string $intent, int $media_count): array {
        $requirements = [];
        $normalized = mb_strtolower($intent);
        $has_comparison = (bool) preg_match(
            '/\b(compare|compared|comparing|comparison|versus|vs\.?|difference|differences|alternative)\b/i',
            $normalized,
        );
        $has_benefits = (bool) preg_match(
            '/\b(benefit|benefits|advantage|advantages|feature|features|why\s+choose)\b/i',
            $normalized,
        );
        $has_standalone_explanation = (bool) preg_match(
            '/\b(rules?|how\s+.+\s+works?|guide|instructions?|details?|reference)\b/i',
            $normalized,
        );
        $has_generic_explanation = (bool) preg_match('/\b(explain|explains|explaining)\b/i', $normalized);

        // One comparison section can also explain benefits. Do not select
        // several visually repetitive sections for one semantic requirement.
        if ($has_comparison) {
            $requirements[] = 'comparison parallel topics';
        } elseif ($has_benefits) {
            $requirements[] = 'benefits features';
        }

        if ($has_standalone_explanation || $has_generic_explanation && !$has_comparison && !$has_benefits) {
            $requirements[] = 'explanation information';
        }

        $facets = [
            '/\b(steps?|process|timeline|workflow|how\s+to)\b/i' => 'steps features',
            '/\b(facts?|statistics?|stats?|metrics?|numbers?|impact)\b/i' => 'facts metrics impact',
            '/\b(contact|register|apply|sign\s+up|next\s+step|call\s+to\s+action|cta)\b/i' => 'next step call to action',
            '/\b(gallery|image\s+cards?|photo\s+grid|visual\s+section)\b/i' => 'three features images',
        ];

        foreach ($facets as $pattern => $query) {
            if (!preg_match($pattern, $normalized)) {
                continue;
            }

            $requirements[] = $query;
        }

        // When no common signal matched, preserve extensibility by ranking the
        // user's own clauses. Domain packs can add intents without AWPT changes.
        if ([] === $requirements) {
            $clauses = preg_split('/(?:[,;.!?]+|\b(?:and|plus|along\s+with|include|including|add|with)\b)/i', $intent);

            if (is_array($clauses)) {
                foreach ($clauses as $clause) {
                    $clause = trim(sanitize_text_field($clause));

                    if (count(preg_split('/\s+/', $clause) ?: []) >= 3) {
                        $requirements[] = $clause;
                    }
                }
            }
        }

        unset($media_count);

        return array_values(array_unique($requirements));
    }

    /**
     * @param list<array<string, mixed>> $recommendations
     * @param list<string> $excluded_names
     * @return array<string, mixed>
     */
    private function first_relevant_section_pattern(array $recommendations, array $excluded_names): array {
        foreach ($recommendations as $recommendation) {
            $pattern = ArrayKey::as_map($recommendation['pattern'] ?? null);
            $domain = ArrayKey::as_map($pattern['domain'] ?? null);
            $name = (string) ($pattern['name'] ?? '');

            if (
                'page-section' !== sanitize_key((string) ($domain['role'] ?? ''))
                || 'incompatible' === (string) ($pattern['compatibility'] ?? '')
                || in_array($name, $excluded_names, true)
                || !str_starts_with((string) ($recommendation['rationale'] ?? ''), 'Matches intent terms:')
            ) {
                continue;
            }

            return $recommendation;
        }

        return [];
    }

    private function explicit_custom_request(string $intent): bool {
        return (bool) preg_match(
            '/\b(from\s+scratch|bespoke|fully\s+custom|custom\s+layout|without\s+(?:a\s+)?pattern|do\s+not\s+use\s+(?:a\s+)?pattern)\b/i',
            $intent,
        );
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

    /**
     * @param list<array<string, mixed>> $media
     * @return array<string, mixed>
     */
    private function custom_fallback(string $intent, string $post_type, string $reason, array $media = []): array {
        return [
            'mode' => 'custom_fallback',
            'intent' => $intent,
            'post_type' => $post_type,
            'title_strategy' => new ThemePostTitleStrategy()->for_post_type($post_type),
            'reason' => $reason,
            'media' => $media,
            'policy' => 'Use awpt/propose-new-post with a complete custom Gutenberg composition. Pattern materialization is intentionally not required.',
            'agent_feedback' => AgentFeedback::make('ready', $reason),
        ];
    }
}
