<?php

/**
 * awpt/search-knowledge ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Knowledge\KnowledgeIndexer;
use AWPT\Knowledge\KnowledgeQueryNovelty;
use AWPT\Knowledge\KnowledgeSearchCache;
use AWPT\Knowledge\SessionKnowledgeEvidence;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Searches indexed Knowledge and read-only site sources.
 */
final class SearchKnowledge implements AbilityInterface {
    public function register(): void {
        wp_register_ability('awpt/search-knowledge', [
            'label' => __('Search Knowledge', 'agent-wordpress-terminal'),
            'description' => __(
                'Searches Core Knowledge, legacy guidelines, site content, and allowed read-only document sources.',
                'agent-wordpress-terminal',
            ),
            'category' => 'awpt',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => __('Search query.', 'agent-wordpress-terminal'),
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => __('Maximum result count.', 'agent-wordpress-terminal'),
                    ],
                    'purpose' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional concrete evidence gap this refined search should fill.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'session_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Current AWPT session ID. AWPT supplies this automatically.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'seen_chunk_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Session and current-turn chunk IDs supplied automatically by AWPT.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'seen_query_fingerprints' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Prior normalized query fingerprints supplied automatically by AWPT.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'seen_queries' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Prior normalized queries supplied automatically by AWPT.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['query'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
                    'known_matches' => ['type' => 'array'],
                    'novel_count' => ['type' => 'integer'],
                    'reused_count' => ['type' => 'integer'],
                    'exhausted' => ['type' => 'boolean'],
                    'query_fingerprint' => ['type' => 'string'],
                    'query' => ['type' => 'string'],
                    'repeated_query' => ['type' => 'boolean'],
                ],
            ],
            'permission_callback' => [$this, 'can_search'],
            'execute_callback' => [$this, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input Ability input.
     */
    public function can_search(array $input): bool {
        return current_user_can('manage_options') && '' !== trim((string) ($input['query'] ?? ''));
    }

    /**
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $query = (string) ($input['query'] ?? '');
        $session_id = (int) ($input['session_id'] ?? 0);
        $seen_chunk_ids = is_array($input['seen_chunk_ids'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['seen_chunk_ids'])))
            : [];
        $seen_chunk_ids = array_values(array_unique([
            ...new SessionKnowledgeEvidence()->chunk_ids($session_id),
            ...$seen_chunk_ids,
        ]));
        $context = KnowledgeIndexer::retrieval_is_available()
            ? new KnowledgeSearchCache()->context($query, (int) ($input['limit'] ?? 6), $seen_chunk_ids)
            : [
                'query_fingerprint' => hash('sha256', mb_strtolower(trim($query))),
                'items' => [],
                'known_matches' => [],
                'novel_count' => 0,
                'reused_count' => 0,
                'exhausted' => false,
            ];
        $seen_queries = is_array($input['seen_query_fingerprints'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['seen_query_fingerprints'])))
            : [];
        $seen_queries = array_values(array_unique([
            ...new SessionKnowledgeEvidence()->query_fingerprints($session_id),
            ...$seen_queries,
        ]));
        $prior_queries = is_array($input['seen_queries'] ?? null)
            ? array_values(array_filter(array_map('strval', $input['seen_queries'])))
            : [];
        $prior_queries = array_values(array_unique([
            ...new SessionKnowledgeEvidence()->queries($session_id),
            ...$prior_queries,
        ]));
        $repeated_query =
            in_array((string) ($context['query_fingerprint'] ?? ''), $seen_queries, true)
            || new KnowledgeQueryNovelty()->repeats($query, $prior_queries);

        if ($repeated_query) {
            $context['items'] = [];
            $context['novel_count'] = 0;
            $context['exhausted'] = true;
        }

        $items = is_array($context['items'] ?? null) ? $context['items'] : [];

        return [
            'items' => $items,
            'count' => count($items),
            'known_matches' => is_array($context['known_matches'] ?? null) ? $context['known_matches'] : [],
            'novel_count' => (int) ($context['novel_count'] ?? 0),
            'reused_count' => (int) ($context['reused_count'] ?? 0),
            'exhausted' => (bool) ($context['exhausted'] ?? false),
            'query_fingerprint' => (string) ($context['query_fingerprint'] ?? ''),
            'query' => (string) ($context['query'] ?? $query),
            'repeated_query' => $repeated_query,
            'purpose' => sanitize_text_field((string) ($input['purpose'] ?? '')),
        ];
    }
}
