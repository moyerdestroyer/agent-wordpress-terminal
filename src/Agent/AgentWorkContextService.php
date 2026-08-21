<?php

/**
 * Compiles an AWPT-owned workflow spine for the current site task.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\RecommendPatterns;
use AWPT\Domain\DesignCatalog;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\DomainRuleRepository;
use AWPT\Domain\PatternMetadataCatalog;
use AWPT\Support\ArrayKey;
use AWPT\Support\DesignSystemContextService;
use AWPT\Support\PatternCandidateProjector;
use AWPT\Support\PatternCatalog;
use AWPT\Support\SiteDesignContext;

if (!defined('ABSPATH')) {
    exit();
}

final class AgentWorkContextService {
    private DomainPackRegistry $packs;

    public function __construct(?DomainPackRegistry $packs = null) {
        $this->packs = $packs ?? DomainPackRegistry::instance();
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(string $message, TurnProfile $profile, string $post_type = 'page'): array {
        $work_type = $this->work_type($message, $profile);
        $scope = $this->scope($profile);
        $design = new SiteDesignContext()->resolve();
        $gates = $this->gates($work_type);
        $guidance = $this->guidance_refs($scope, $message);
        $design_catalog = new DesignCatalog($this->packs);
        $design_snapshot = new DesignSystemContextService(null, $design_catalog)->snapshot($scope);
        $patterns = [];

        if ('compose' === $work_type || $profile->is_redesign() && !$profile->is_improve_evaluate()) {
            $recommended = new RecommendPatterns(
                new PatternCatalog(null, new PatternMetadataCatalog($this->packs)),
                $this->packs,
            )->execute([
                'intent' => $message,
                'post_type' => $post_type,
                'max' => 4,
                'semantic' => false,
            ]);
            $patterns = new PatternCandidateProjector()->many(
                ArrayKey::list_of_maps($recommended['recommendations'] ?? null),
                4,
                4_500,
            );
        }

        return [
            'work_type' => $work_type,
            'design_scope' => $scope,
            'design_detail' => $this->design_detail($profile),
            'intent' => sanitize_textarea_field($message),
            'workflow' => [
                'phases' => ['discover', 'decide', 'propose', 'validate', 'human_review'],
                'current_phase' => 'discover',
                'evidence_gates' => $gates,
                'correction_limit' => 1,
            ],
            'design_authority' => [
                'theme_name' => $design['theme_name'],
                'stylesheet' => $design['stylesheet'],
                'template' => $design['template'],
                'preferred_pattern_namespaces' => ArrayKey::list_of_strings($design['preferred_pattern_namespaces']),
                'context_hash' => (string) ($design_snapshot['hash'] ?? ''),
                'catalog_hash' => $design_catalog->hash(),
                'pattern_catalog' => $design_snapshot['pattern_catalog'] ?? [],
                'pattern_catalog_hash' => (string) ($design_snapshot['pattern_catalog_hash'] ?? ''),
                'guidance_ids' => $design_catalog->guidance_for($scope),
            ],
            'domain_packs' => $this->packs->status(),
            'guidance' => $guidance,
            'rules' => new DomainRuleRepository($this->packs)->summary(),
            'pattern_candidates' => $patterns,
            'safety' => [
                'writes_are_staged' => true,
                'human_apply_required' => true,
                'read_before_write' => true,
                'safe_fixes_only_on_proposed_copy' => true,
            ],
        ];
    }

    public function format_for_prompt(string $message, TurnProfile $profile, int $max_chars = 8_000): string {
        if (TurnProfile::TOOL_CHAT === $profile->tool_profile) {
            return '';
        }

        $context = $this->compile($message, $profile);
        unset($context['intent']);
        $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            return '';
        }

        if (strlen($encoded) > max(1_000, $max_chars)) {
            // Preserve valid JSON and every workflow/safety contract. Candidates and
            // guidance are detail channels that can be retrieved by their abilities.
            $context['pattern_candidates'] = array_slice(
                ArrayKey::list_of_maps($context['pattern_candidates'] ?? null),
                0,
                4,
            );
            $context['guidance'] = array_slice(ArrayKey::list_of_maps($context['guidance'] ?? null), 0, 4);
            $context['domain_packs'] = array_map(
                static fn(array $pack): array => array_intersect_key($pack, array_flip([
                    'id',
                    'version',
                    'enabled',
                    'design_catalog',
                    'diagnostic_count',
                ])),
                ArrayKey::list_of_maps($context['domain_packs'] ?? null),
            );
            $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", [
            '<awpt-work-context>',
            'This is the AWPT-owned workflow contract for the current turn. Satisfy its evidence gates, use the active theme as design authority, and move to one staged proposal once evidence is sufficient. Theme guidance refines judgment but does not redefine this workflow.',
            $encoded,
            '</awpt-work-context>',
        ]);
    }

    /**
     * Design-catalog / guidance-set key for this turn.
     *
     * Distinct from {@see TurnProfile::$work_mode}, which is a routing flag
     * (`create`, `redesign`, `edit`) and is not a catalog key.
     */
    public function scope(TurnProfile $profile): string {
        if ($profile->is_improve_evaluate()) {
            return 'evaluate';
        }

        return $this->work_type($profile->message, $profile);
    }

    /**
     * How much compiled design evidence belongs in the always-on prompt slice.
     */
    public function design_detail(TurnProfile $profile): string {
        $scope = $this->scope($profile);

        if ($profile->is_improve_evaluate()) {
            // Evaluation gets the catalog index; concrete names require recommendation evidence.
            return DesignSystemContextService::DETAIL_EVALUATE;
        }

        if ('global_styles' === $scope) {
            return DesignSystemContextService::DETAIL_GLOBAL_STYLES;
        }

        if ('compose' === $scope || $profile->is_redesign() && !$profile->is_improve_evaluate()) {
            return DesignSystemContextService::DETAIL_COMPOSE;
        }

        return DesignSystemContextService::DETAIL_SLIM;
    }

    private function work_type(string $message, TurnProfile $profile): string {
        return match (true) {
            $profile->is_improve_evaluate(), $profile->is_improve_act() => 'edit',
            $profile->needs_diagnosis_module() => 'diagnose',
            (bool) preg_match('/\b(navigation|menu|submenu|nav)\b/i', $message) => 'navigation',
            (bool) preg_match(
                '/\b(global styles?|theme\.json|palette|site-wide|font family|design tokens?)\b/i',
                $message,
            )
                => 'global_styles',
            $profile->needs_template_module()
                && (bool) preg_match('/\b(template|header|footer|template part)\b/i', $message)
                => 'template',
            $profile->needs_edit_module() => 'edit',
            $profile->needs_compose_module() => 'compose',
            default => 'investigate',
        };
    }

    /**
     * @return list<array{evidence: string, abilities: list<string>}>
     */
    private function gates(string $work_type): array {
        return match ($work_type) {
            'compose' => [
                [
                    'evidence' => 'active design context and compatible pattern candidates',
                    'abilities' => ['awpt/recommend-patterns', 'awpt/read-design-system'],
                ],
                [
                    'evidence' => 'exact selected pattern structure or an explicit fallback reason',
                    'abilities' => ['awpt/read-pattern'],
                ],
            ],
            'edit' => [
                [
                    'evidence' => 'active design system, accessibility guidance, and composition rubric',
                    'abilities' => ['awpt/read-design-system', 'awpt/read-domain-guidance'],
                ],
                [
                    'evidence' => 'resolved target and current content',
                    'abilities' => ['awpt/search-content', 'awpt/read-content'],
                ],
                [
                    'evidence' => 'block paths and fingerprints for the smallest safe operation',
                    'abilities' => ['awpt/read-block-tree', 'awpt/get-block'],
                ],
            ],
            'template' => [[
                'evidence' => 'exact template or part and current markup',
                'abilities' => ['awpt/list-templates', 'awpt/read-template'],
            ]],
            'navigation' => [[
                'evidence' => 'exact navigation resource and current items',
                'abilities' => ['awpt/list-wordpress-resources', 'awpt/read-wordpress-resource'],
            ]],
            'global_styles' => [[
                'evidence' => 'current merged design tokens and active style revision',
                'abilities' => ['awpt/read-design-system', 'awpt/read-global-styles'],
            ]],
            'diagnose' => [[
                'evidence' => 'observed failure and relevant site/runtime evidence',
                'abilities' => ['awpt/diagnose-error', 'awpt/inspect-frontend', 'awpt/read-error-log'],
            ]],
            default => [[
                'evidence' => 'verified site evidence relevant to the claim',
                'abilities' => ['awpt/find-abilities'],
            ]],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function guidance_refs(string $work_type, string $message): array {
        $refs = [];
        $lower = mb_strtolower($message);
        $forced = array_fill_keys(new DesignCatalog($this->packs)->guidance_for($work_type), true);

        foreach ($this->packs->active() as $pack) {
            foreach (ArrayKey::list_of_maps($pack['guidance'] ?? null) as $guidance) {
                $scopes = ArrayKey::list_of_strings($guidance['applies_to'] ?? null);
                $triggers = ArrayKey::list_of_strings($guidance['triggers'] ?? null);
                $scope_match = in_array('all', $scopes, true) || in_array($work_type, $scopes, true);
                $trigger_match =
                    isset($forced[(string) ($guidance['id'] ?? '')])
                    || [] === $triggers
                    || [] !== array_filter($triggers, static fn(string $trigger): bool => str_contains(
                        $lower,
                        mb_strtolower($trigger),
                    ));

                if (!$scope_match || !$trigger_match) {
                    continue;
                }

                $refs[] = [
                    'pack_id' => (string) $pack['id'],
                    'id' => (string) $guidance['id'],
                    'label' => (string) $guidance['label'],
                    'hard' => ArrayKey::rest_bool($guidance['hard']),
                    'priority' => (int) $guidance['priority'],
                    'ability' => 'awpt/read-domain-guidance',
                ];
            }
        }

        usort($refs, static fn(array $left, array $right): int => $right['priority'] <=> $left['priority']);

        return $refs;
    }
}
