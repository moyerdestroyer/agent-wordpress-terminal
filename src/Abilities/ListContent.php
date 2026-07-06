<?php

/**
 * awpt/list-content ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\ContentListService;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists and browses WordPress content with filters, sorting, and inventory totals.
 */
final class ListContent
{
    public function register(): void
    {
        AbilityRegistrar::register([
            'name' => 'awpt/list-content',
            'label' => __('List Content', 'agent-wordpress-terminal'),
            'description' => __(
                'Lists and browses WordPress content with filters for post type, status, author, and search text. Returns totals plus item metadata such as author, dates, and excerpt. Use awpt/search-content to resolve one specific item by title, slug, ID, or URL.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_type' => [
                        'type' => 'string',
                        'description' => __(
                            'Post type to list: post, page, wp_template, wp_template_part, wp_block, attachment, or all. Defaults to post.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional status filter: publish, draft, pending, private, or future.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'author' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional author filter by user ID, login, display name, or email.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional title or content search term for narrowing the list.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'orderby' => [
                        'type' => 'string',
                        'description' => __(
                            'Sort field: modified, date, title, author, or type. Defaults to modified.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'order' => [
                        'type' => 'string',
                        'description' => __(
                            'Sort direction: ASC or DESC. Defaults to DESC.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => __(
                            'Number of items to skip for pagination. Defaults to 0.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => __(
                            'Maximum items to return. Defaults to 20, maximum 100.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_type' => ['type' => 'string'],
                    'post_types' => ['type' => 'array'],
                    'status' => ['type' => 'string'],
                    'filters' => ['type' => 'object'],
                    'items' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'has_more' => ['type' => 'boolean'],
                    'totals_by_status' => ['type' => 'object'],
                    'totals_by_type' => ['type' => 'object'],
                ],
            ],
            'permission_callback' => [$this, 'can_list'],
            'execute_callback' => [$this, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_list(array $input): bool
    {
        unset($input);

        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        return new ContentListService()->list($input);
    }
}
