<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\PatternSemanticReranker;
use AWPT\Support\ArrayKey;
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
                'Ranks active-theme patterns for a concrete page intent and explains the matching domain guidance.',
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
                    if (str_contains(mb_strtolower($value), $term)) {
                        $relevance += $weight;
                        $term_matched = true;
                    }
                }

                if ($term_matched) {
                    $matched[] = $term;
                }
            }

            $score = $relevance;
            $score += 'active_theme' === (string) ($pattern['owner'] ?? '') ? 40 : 0;
            $score += [] !== $domain ? 30 : 0;

            $ranked[] = [
                'score' => $score,
                'pattern' => $pattern,
                'rationale' => [] !== $matched
                    ? sprintf(
                        __('Matches intent terms: %s.', 'agent-wordpress-terminal'),
                        implode(', ', array_unique($matched)),
                    )
                    : __('Compatible active-theme pattern.', 'agent-wordpress-terminal'),
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

        $semantic = !array_key_exists('semantic', $input) || (bool) $input['semantic'];
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
