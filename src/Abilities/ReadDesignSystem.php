<?php

/**
 * awpt/read-design-system ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\DesignSystemContextService;

if (!defined('ABSPATH')) {
    exit();
}

final class ReadDesignSystem implements AbilityInterface {
    public function register(): void {
        /** @var array<string, mixed> $input_schema */
        $input_schema = [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            'tokens',
                            'components',
                            'style_variations',
                            'archetypes',
                            'patterns',
                            'constraints',
                        ],
                    ],
                    'maxItems' => 6,
                ],
                'intent' => ['type' => 'string'],
                'block' => ['type' => 'string'],
                'scope' => [
                    'type' => 'string',
                    'enum' => [
                        'compose',
                        'edit',
                        'evaluate',
                        'template',
                        'navigation',
                        'global_styles',
                        'diagnose',
                        'investigate',
                    ],
                ],
            ],
        ];
        /** @var array<string, mixed> $output_schema */
        $output_schema = ['type' => 'object'];
        /** @var callable(array<string, mixed>): bool $permission_callback */
        $permission_callback = [$this, 'can_read'];
        /** @var callable(array<string, mixed>): (array<string, mixed>|\WP_Error) $execute_callback */
        $execute_callback = [$this, 'execute'];

        AbilityRegistrar::register([
            'name' => 'awpt/read-design-system',
            'label' => __('Read Design System', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads bounded active-theme tokens, components, style variations, archetypes, patterns, and constraints with provenance.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => $input_schema,
            'output_schema' => $output_schema,
            'permission_callback' => $permission_callback,
            'execute_callback' => $execute_callback,
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        unset($input);
        return current_user_can('edit_posts');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function execute(array $input): array {
        $sections = is_array($input['sections'] ?? null)
            ? array_values(array_filter($input['sections'], 'is_string'))
            : [];
        return new DesignSystemContextService()->read(
            $sections,
            sanitize_key((string) ($input['scope'] ?? 'edit')),
            sanitize_text_field((string) ($input['intent'] ?? '')),
            sanitize_text_field((string) ($input['block'] ?? '')),
        );
    }
}
