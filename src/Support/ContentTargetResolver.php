<?php

/**
 * Resolves human content references to one readable post.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Turns an ID, URL, slug, or title query into an exact/ambiguous/missing result.
 */
final class ContentTargetResolver
{
    private ContentSearchService $search;

    public function __construct(?ContentSearchService $search = null)
    {
        $this->search = $search ?? new ContentSearchService();
    }

    /**
     * @return array{status: 'resolved'|'ambiguous'|'missing', query: string, post_id?: int, result?: array<array-key, mixed>, results?: list<array<array-key, mixed>>}
     */
    public function resolve(string $query, string $post_type = '', int $limit = 5): array
    {
        $query = trim($query);

        if ('' === $query) {
            return [
                'status' => 'missing',
                'query' => $query,
                'results' => [],
            ];
        }

        $search = $this->search->search([
            'query' => $query,
            'post_type' => $post_type,
            'limit' => $limit,
        ]);
        $results = $this->list_results($search['results'] ?? []);

        if ([] === $results) {
            return [
                'status' => 'missing',
                'query' => $query,
                'results' => [],
            ];
        }

        $first = $results[0];

        if ('exact' === (string) ($first['matched_by'] ?? '') || 1 === count($results)) {
            return [
                'status' => 'resolved',
                'query' => $query,
                'post_id' => (int) ($first['id'] ?? 0),
                'result' => $first,
                'results' => $results,
            ];
        }

        return [
            'status' => 'ambiguous',
            'query' => $query,
            'results' => $results,
        ];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function list_results(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $results = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $results[] = $item;
        }

        return $results;
    }
}
