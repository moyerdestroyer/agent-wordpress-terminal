<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\PatternSemanticReranker;
use AWPT\Support\ArrayKey;
use AWPT\Support\PageSectionModel;
use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

/** Recommends compatible patterns using curated domain semantics. */
final class RecommendPatterns implements AbilityInterface {
    private PatternCatalog $patterns;

    private DomainPackRegistry $domain_packs;

    public function __construct(?PatternCatalog $patterns = null, ?DomainPackRegistry $domain_packs = null) {
        $this->patterns = $patterns ?? new PatternCatalog();
        $this->domain_packs = $domain_packs ?? DomainPackRegistry::instance();
    }

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/recommend-patterns',
            'label' => __('Recommend Patterns', 'agent-wordpress-terminal'),
            'description' => __(
                'Ranks active-theme patterns for a concrete page intent and explains the matching domain guidance. Optional target_role / post_id+target_path boost section-scoped matches.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => ['type' => 'string'],
                    'post_type' => ['type' => 'string'],
                    'max' => ['type' => 'integer'],
                    'semantic' => [
                        'type' => 'boolean',
                        'description' => __(
                            'Use optional configured embeddings to rerank local candidates.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'target_role' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional page-section role (header, hero, faq, cta, …) to boost matching patterns.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'section_role' => [
                        'type' => 'string',
                        'description' => __('Alias of target_role.', 'agent-wordpress-terminal'),
                    ],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional post to load section outline from when target_path is set.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'target_path' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional block path used with post_id to resolve target_role.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'prefer_section_scope' => [
                        'type' => 'boolean',
                        'description' => __(
                            'When true, boost section-scoped patterns and soft-demote full page layouts.',
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
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $intent = sanitize_text_field((string) ($input['intent'] ?? ''));
        $post_type = sanitize_key((string) ($input['post_type'] ?? 'page'));
        $max = max(1, min(24, (int) ($input['max'] ?? 8)));
        $terms = $this->terms($intent);
        $target_role = sanitize_key((string) ($input['target_role'] ?? $input['section_role'] ?? ''));
        $post_id = max(0, (int) ($input['post_id'] ?? 0));
        $target_path = sanitize_text_field((string) ($input['target_path'] ?? ''));
        $resolved_from_post = false;

        if ('' === $target_role && $post_id > 0 && '' !== $target_path) {
            $post = get_post($post_id);

            if ($post instanceof \WP_Post) {
                $section = PageSectionModel::find_by_path(PageSectionModel::from_content($post->post_content, [
                    'title' => $post->post_title,
                    'post_type' => $post->post_type,
                ]), $target_path);

                if (is_array($section)) {
                    $target_role = sanitize_key((string) ($section['role'] ?? ''));
                    $resolved_from_post = '' !== $target_role;
                }
            }
        }

        $prefer_section_scope = array_key_exists('prefer_section_scope', $input)
            ? ArrayKey::rest_bool($input['prefer_section_scope'])
            : '' !== $target_role;

        $ranked = [];

        foreach ($this->patterns->list('', 200, $post_type) as $pattern) {
            if ('incompatible' === (string) ($pattern['compatibility'] ?? '')) {
                continue;
            }

            $domain = is_array($pattern['domain'] ?? null) ? $pattern['domain'] : [];
            $weighted_fields = [
                [(string) ($pattern['name'] ?? ''), 18],
                [(string) ($pattern['title'] ?? ''), 14],
                [(string) ($domain['role'] ?? ''), 10],
                [implode(' ', ArrayKey::list_of_strings($domain['intents'] ?? null)), 16],
                [implode(' ', ArrayKey::list_of_strings($domain['search_terms'] ?? null)), 14],
                [implode(' ', ArrayKey::list_of_strings($domain['use_when'] ?? null)), 8],
                [(string) ($domain['summary'] ?? ''), 5],
                [(string) ($pattern['description'] ?? ''), 3],
            ];
            $matched = [];
            $relevance = 0;

            foreach ($terms as $term) {
                $term_matched = false;

                foreach ($weighted_fields as [$value, $weight]) {
                    if (!str_contains(mb_strtolower($value), $term)) {
                        continue;
                    }

                    $relevance += $weight;
                    $term_matched = true;
                }

                if ($term_matched) {
                    $matched[] = $term;
                }
            }

            $score = $relevance;
            $score += 'active_theme' === (string) ($pattern['owner'] ?? '') ? 40 : 0;
            $score += [] !== $domain ? 30 : 0;

            $affinity = $this->section_role_affinity($target_role, $prefer_section_scope, [
                'role' => (string) ($domain['role'] ?? ''),
                'composition_scope' => (string) ($pattern['composition_scope'] ?? ''),
                'name' => (string) ($pattern['name'] ?? ''),
                'title' => (string) ($pattern['title'] ?? ''),
            ]);
            $score += $affinity['score'];

            $rationale_parts = [];

            if ([] !== $matched) {
                $rationale_parts[] = sprintf(
                    __('Matches intent terms: %s.', 'agent-wordpress-terminal'),
                    implode(', ', array_unique($matched)),
                );
            }

            if ('' !== $affinity['rationale']) {
                $rationale_parts[] = $affinity['rationale'];
            }

            if ([] === $rationale_parts) {
                $rationale_parts[] = __('Compatible active-theme pattern.', 'agent-wordpress-terminal');
            }

            $ranked[] = [
                'score' => $score,
                'pattern' => $pattern,
                'matched_terms' => array_values(array_unique($matched)),
                'rationale' => implode(' ', $rationale_parts),
            ];
        }

        foreach ($this->domain_packs->active() as $pack) {
            foreach ($this->domain_packs->recommenders((string) $pack['id']) as $recommender) {
                $callback = $recommender['callback'] ?? null;

                if (is_callable($callback)) {
                    $custom = $callback($ranked, $intent, $post_type, $pack);

                    if (is_array($custom)) {
                        $ranked = ArrayKey::list_of_maps($custom);
                    }
                }
            }
        }

        $semantic = !array_key_exists('semantic', $input) || ArrayKey::rest_bool($input['semantic']);
        $reranked = $semantic
            ? new PatternSemanticReranker()->rerank($ranked, $intent)
            : ['ranked' => $ranked, 'mode' => 'deterministic'];
        $ranked = $reranked['ranked'];

        usort($ranked, static function (array $left, array $right): int {
            $score = (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);

            return 0 !== $score
                ? $score
                : strnatcasecmp(
                    (string) ($left['pattern']['title'] ?? ''),
                    (string) ($right['pattern']['title'] ?? ''),
                );
        });

        $recommendations = array_slice($ranked, 0, $max);

        return [
            'intent' => $intent,
            'post_type' => $post_type,
            'target_role' => '' !== $target_role ? $target_role : null,
            'prefer_section_scope' => $prefer_section_scope,
            'target_role_resolved_from_post' => $resolved_from_post,
            'recommendations' => $recommendations,
            'ranking_mode' => $reranked['mode'],
            'policy' => 'Recommendations are read-only candidates. Read a selected pattern before using it.',
            'agent_feedback' => AgentFeedback::make(
                [] !== $recommendations ? 'ready' : 'needs_evidence',
                [] !== $recommendations
                    ? __('Read the best-fitting candidate before adapting or staging it.', 'agent-wordpress-terminal')
                    : __(
                        'No compatible pattern candidate was found; document the fallback before custom composition.',
                        'agent-wordpress-terminal',
                    ),
                [
                    'next_actions' => [] !== $recommendations
                        ? [[
                            'ability' => 'awpt/read-pattern',
                            'reason' => __(
                                'Inspect exact block structure and dependencies.',
                                'agent-wordpress-terminal',
                            ),
                            'input' => ['name' => (string) ($recommendations[0]['pattern']['name'] ?? '')],
                        ]]
                        : [],
                ],
            ),
        ];
    }

    /**
     * Soft boost / demote based on page-section role vs pattern domain role / scope.
     *
     * @param array{role: string, composition_scope: string, name: string, title: string} $pattern
     * @return array{score: int, rationale: string}
     */
    private function section_role_affinity(string $section_role, bool $prefer_section_scope, array $pattern): array {
        $score = 0;
        $notes = [];
        $blob = mb_strtolower(trim(implode(' ', $pattern)), 'UTF-8');
        $pattern_role_l = mb_strtolower($pattern['role'], 'UTF-8');
        $scope_l = mb_strtolower($pattern['composition_scope'], 'UTF-8');

        if ('' !== $section_role) {
            $aliases = $this->section_role_aliases($section_role);
            $matched_alias = '';

            foreach ($aliases as $alias) {
                if (!($pattern_role_l === $alias || $scope_l === $alias || str_contains($blob, $alias))) {
                    continue;
                }

                $matched_alias = $alias;
                break;
            }

            if ('' !== $matched_alias) {
                $score += 28;
                $notes[] = sprintf(
                    /* translators: 1: section role, 2: matched alias */
                    __('Boosted for section role %1$s (matched %2$s).', 'agent-wordpress-terminal'),
                    $section_role,
                    $matched_alias,
                );
            }
        }

        if ($prefer_section_scope) {
            if (in_array($scope_l, ['section', 'hero', 'cta', 'media'], true)) {
                $score += 15;
                $notes[] = __('Prefers section-scoped composition.', 'agent-wordpress-terminal');
            }

            if (
                in_array($scope_l, ['layout', 'page'], true) || in_array($pattern_role_l, ['page-layout', 'page'], true)
            ) {
                $score -= 22;
                $notes[] = __('Soft-demoted full-page layout for section change.', 'agent-wordpress-terminal');
            }
        }

        return [
            'score' => $score,
            'rationale' => implode(' ', array_unique($notes)),
        ];
    }

    /**
     * @return list<string>
     */
    private function section_role_aliases(string $section_role): array {
        return match ($section_role) {
            PageSectionModel::ROLE_HEADER => ['header', 'page-introduction', 'title', 'masthead', 'hero'],
            PageSectionModel::ROLE_HERO => ['hero', 'cover', 'banner', 'page-introduction', 'header'],
            PageSectionModel::ROLE_BODY => ['body', 'content', 'section', 'about', 'text'],
            PageSectionModel::ROLE_STEPS => ['steps', 'process', 'how-to', 'howto', 'timeline'],
            PageSectionModel::ROLE_FAQ => ['faq', 'accordion', 'questions', 'details'],
            PageSectionModel::ROLE_CTA => ['cta', 'call-to-action', 'contact', 'buttons'],
            PageSectionModel::ROLE_QUERY => ['query', 'news', 'listing', 'posts', 'loop', 'archive'],
            PageSectionModel::ROLE_MEDIA => ['media', 'gallery', 'image', 'photo', 'video'],
            default => [$section_role],
        };
    }

    /**
     * @return list<string>
     */
    private function terms(string $intent): array {
        $parts = preg_split('/[^a-z0-9]+/i', mb_strtolower($intent));

        if (false === $parts) {
            return [];
        }

        $stopwords = array_fill_keys([
            'a',
            'an',
            'and',
            'are',
            'as',
            'at',
            'be',
            'block',
            'build',
            'by',
            'content',
            'create',
            'for',
            'from',
            'in',
            'into',
            'is',
            'it',
            'make',
            'need',
            'of',
            'on',
            'or',
            'page',
            'section',
            'site',
            'that',
            'the',
            'this',
            'to',
            'want',
            'website',
            'with',
            'wordpress',
        ], true);

        return array_values(array_unique(array_filter(
            $parts,
            static fn(string $part): bool => mb_strlen($part) >= 3 && !isset($stopwords[$part]),
        )));
    }
}
