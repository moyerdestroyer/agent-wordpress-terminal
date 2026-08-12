<?php

/**
 * Compiles an AWPT-owned workflow spine for the current site task.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\RecommendPatterns;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\DomainRuleRepository;
use AWPT\Support\ArrayKey;
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
        $design = new SiteDesignContext()->resolve();
        $gates = $this->gates($work_type);
        $guidance = $this->guidance_refs($work_type, $message);
        $patterns = [];

        if ('compose' === $work_type) {
            $recommended = new RecommendPatterns()->execute([
                'intent' => $message,
                'post_type' => $post_type,
                'max' => 4,
                'semantic' => false,
            ]);
            $patterns = array_values(array_map(static fn(array $row): array => [
                'name' => (string) ($row['pattern']['name'] ?? ''),
                'title' => (string) ($row['pattern']['title'] ?? ''),
                'score' => (int) ($row['score'] ?? 0),
                'rationale' => (string) ($row['rationale'] ?? ''),
            ], ArrayKey::list_of_maps($recommended['recommendations'] ?? null)));
        }

        return [
            'work_type' => $work_type,
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

    public function format_for_prompt(string $message, TurnProfile $profile, int $max_chars = 5_000): string {
        if (TurnProfile::TOOL_CHAT === $profile->tool_profile) {
            return '';
        }

        $context = $this->compile($message, $profile);
        $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            return '';
        }

        $encoded = mb_substr($encoded, 0, max(1_000, $max_chars), 'UTF-8');

        return implode("\n", [
            '<awpt-work-context>',
            'This is the AWPT-owned workflow contract for the current turn. Satisfy its evidence gates, use the active theme as design authority, and move to one staged proposal once evidence is sufficient. Theme guidance refines judgment but does not redefine this workflow.',
            $encoded,
            '</awpt-work-context>',
        ]);
    }

    private function work_type(string $message, TurnProfile $profile): string {
        return match (true) {
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
                    'abilities' => ['awpt/recommend-patterns', 'awpt/read-theme-json'],
                ],
                [
                    'evidence' => 'exact selected pattern structure or an explicit fallback reason',
                    'abilities' => ['awpt/read-pattern'],
                ],
            ],
            'edit' => [
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
                'abilities' => ['awpt/read-theme-json', 'awpt/read-global-styles'],
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

        foreach ($this->packs->active() as $pack) {
            foreach (ArrayKey::list_of_maps($pack['guidance'] ?? null) as $guidance) {
                $scopes = ArrayKey::list_of_strings($guidance['applies_to'] ?? null);
                $triggers = ArrayKey::list_of_strings($guidance['triggers'] ?? null);
                $scope_match = in_array('all', $scopes, true) || in_array($work_type, $scopes, true);
                $trigger_match =
                    [] === $triggers
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
                    'hard' => (bool) $guidance['hard'],
                    'priority' => (int) $guidance['priority'],
                    'ability' => 'awpt/read-domain-guidance',
                ];
            }
        }

        usort($refs, static fn(array $left, array $right): int => $right['priority'] <=> $left['priority']);

        return $refs;
    }
}
