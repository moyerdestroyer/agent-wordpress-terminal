<?php

/**
 * awpt/search-content ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\ContentSearchService;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Finds editable/readable WordPress content by natural identifiers.
 */
final class SearchContent {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/search-content',
            'label' => __('Search Content', 'agent-wordpress-terminal'),
            'description' => __(
                'Finds posts, pages, templates, template parts, and reusable blocks by title, slug, ID, or URL.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => __('Title, slug, post ID, or URL to search for.', 'agent-wordpress-terminal'),
                    ],
                    'post_type' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional post type filter such as page, post, wp_template, wp_template_part, or wp_block.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => __('Maximum number of results. Defaults to 10.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['query'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'results' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => [$this, 'can_search'],
            'execute_callback' => [$this, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_search(array $input): bool {
        unset($input);

        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        return new ContentSearchService()->search($input);
    }
}
