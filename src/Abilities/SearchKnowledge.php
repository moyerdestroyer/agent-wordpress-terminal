<?php

/**
 * awpt/search-knowledge ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Knowledge\KnowledgeSearchService;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Searches indexed Knowledge and read-only site sources.
 */
final class SearchKnowledge
{
    public function register(): void
    {
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
                ],
                'required' => ['query'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
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
    public function can_search(array $input): bool
    {
        return current_user_can('manage_options') && '' !== trim((string) ($input['query'] ?? ''));
    }

    /**
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        $items = (new KnowledgeSearchService())->search((string) ($input['query'] ?? ''), (int) ($input['limit'] ?? 6));

        return [
            'items' => $items,
            'count' => count($items),
        ];
    }
}
