<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\BlockTree;
use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

/** Reads a pattern's raw block composition before the agent reuses it. */
final class ReadPattern implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-pattern',
            'label' => __('Read Pattern', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads one registered or reusable pattern with its Gutenberg block tree.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'purpose' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional concrete layout role or compatibility question this read should answer.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['name'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        unset($input);
        return current_user_can('edit_posts');
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        $catalog = new PatternCatalog();
        $pattern = $catalog->find($name);

        if (null === $pattern) {
            $suggestions = $catalog->suggestions($name, 8);

            return new \WP_Error(
                'awpt_pattern_not_found',
                __(
                    'Pattern not found. Use an exact name from awpt/list-patterns (do not invent slugs).',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 404,
                    'requested_name' => $name,
                    'suggested_patterns' => array_map(static fn(array $item): array => [
                        'name' => (string) ($item['name'] ?? ''),
                        'title' => (string) ($item['title'] ?? ''),
                        'owner' => (string) ($item['owner'] ?? ''),
                    ], $suggestions),
                    'recommended_next_tools' => [
                        [
                            'tool' => 'awpt/list-patterns',
                            'input' => ['search' => '', 'max' => 24],
                        ],
                    ],
                ],
            );
        }

        $content = (string) ($pattern['content'] ?? '');
        $tree = BlockTree::from_content($content);

        return array_merge($catalog->summary($pattern), [
            'content' => $content,
            'blocks' => $tree->normalized(),
            'design_dependencies' => $catalog->design_dependencies($content),
        ]);
    }
}
