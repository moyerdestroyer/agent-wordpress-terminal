<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

/** Lists registered theme/core and reusable block patterns. */
final class ListPatterns implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/list-patterns',
            'label' => __('List Patterns', 'agent-wordpress-terminal'),
            'description' => __(
                'Lists registered theme/core and reusable block patterns available for composition.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional metadata filter over pattern name, title, description, and category. Use layout terms such as hero, CTA, image, columns, or leave empty to browse; page topics usually are not pattern metadata.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'max' => ['type' => 'integer'],
                ],
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
        $catalog = new PatternCatalog();
        $search = sanitize_text_field((string) ($input['search'] ?? ''));
        $patterns = $catalog->list($search, (int) ($input['max'] ?? 100));

        $output = [
            'count' => count($patterns),
            'patterns' => $patterns,
            'search' => $search,
        ];

        if ('' !== $search && [] === $patterns) {
            $output['search_note'] = __(
                'No pattern metadata matched this search. Pattern search matches names, titles, descriptions, and categories—not the subject of the page. This does not mean compatible patterns are unavailable.',
                'agent-wordpress-terminal',
            );
            $output['available_count'] = count($catalog->list('', 200));
            $output['suggested_patterns'] = $catalog->suggestions($search, 12);
            $output['recommended_next_tools'] = [
                ['tool' => 'awpt/list-patterns', 'input' => ['search' => '', 'max' => 24]],
            ];
        }

        return $output;
    }
}
