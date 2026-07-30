<?php

/**
 * awpt/list-wordpress-resources ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\WordPressResourceReader;

if (!defined('ABSPATH')) {
    exit();
}

final class ListWordPressResources implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/list-wordpress-resources',
            'label' => __('List WordPress Resources', 'agent-wordpress-terminal'),
            'description' => __(
                'Inventories non-content WordPress resources. resource_type supports taxonomies, terms, menus, menu_items, users, comments, widget_areas, registered_settings, and post_meta; integrations may extend it.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'resource_type' => ['type' => 'string'],
                    'taxonomy' => ['type' => 'string'],
                    'menu_id' => ['type' => 'integer'],
                    'post_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string'],
                    'search' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                ],
                'required' => ['resource_type'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        return new WordPressResourceReader()->can_read($input);
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        return new WordPressResourceReader()->list($input);
    }
}
