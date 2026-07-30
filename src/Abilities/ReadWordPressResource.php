<?php

/**
 * awpt/read-wordpress-resource ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\WordPressResourceReader;

if (!defined('ABSPATH')) {
    exit();
}

final class ReadWordPressResource implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-wordpress-resource',
            'label' => __('Read WordPress Resource', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads one exact non-content WordPress resource before proposing a change. Supports term, menu, menu_item, user, comment, registered_setting, and post_meta; integrations may extend it.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'resource_type' => ['type' => 'string'],
                    'resource_id' => ['type' => 'string'],
                    'taxonomy' => ['type' => 'string'],
                    'post_id' => ['type' => 'integer'],
                ],
                'required' => ['resource_type', 'resource_id'],
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
        return new WordPressResourceReader()->read($input);
    }
}
