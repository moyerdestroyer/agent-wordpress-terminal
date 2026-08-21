<?php

/**
 * awpt/find-abilities ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\CoreAbilityCatalog;
use AWPT\Agent\ToolRegistry;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Searches the live, filtered ability catalog and activates matching safe tools
 * for the remainder of the current model turn.
 */
final class FindAbilities implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/find-abilities',
            'label' => __('Find Abilities', 'agent-wordpress-terminal'),
            'description' => __(
                'Searches available WordPress abilities by name and description. Returned ability names become callable for the rest of this turn.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'namespace' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'readonly' => ['type' => 'boolean'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_posts'),
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $query = mb_strtolower(trim((string) ($input['query'] ?? '')));
        $namespace = trim((string) ($input['namespace'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $readonly = array_key_exists('readonly', $input) ? \AWPT\Support\ArrayKey::rest_bool($input['readonly']) : null;
        $limit = min(20, max(1, (int) ($input['limit'] ?? 8)));
        $registry = new ToolRegistry();
        $matches = [];
        $catalog_args = [];

        if ('' !== $namespace) {
            $catalog_args['namespace'] = $namespace;
        }

        if ('' !== $category) {
            $catalog_args['category'] = $category;
        }

        if (null !== $readonly) {
            $catalog_args['meta'] = ['annotations' => ['readonly' => $readonly]];
        }

        if ('' !== $query) {
            $catalog_args['item_include_callback'] = static fn(\WP_Ability $ability) => str_contains(
                mb_strtolower($ability->get_name() . ' ' . $ability->get_description()),
                $query,
            );
        }

        foreach (new CoreAbilityCatalog()->all($catalog_args) as $ability) {
            $name = $ability->get_name();

            if ('awpt/find-abilities' === $name || !$registry->can_auto_execute($name)) {
                continue;
            }

            $description = $ability->get_description();
            $annotations = $registry->annotations_for($name);

            if ('' !== $query && !str_contains(mb_strtolower($name . ' ' . $description), $query)) {
                continue;
            }

            if ('' !== $namespace && !str_starts_with($name, rtrim($namespace, '/') . '/')) {
                continue;
            }

            if ('' !== $category && $category !== $ability->get_category()) {
                continue;
            }

            if (null !== $readonly && $readonly !== (true === ($annotations['readonly'] ?? null))) {
                continue;
            }

            $matches[] = [
                'name' => $name,
                'description' => $description,
                'annotations' => $annotations,
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        $activated = array_values(array_map(static fn(array $tool): string => $tool['name'], $matches));

        return [
            'count' => count($matches),
            'abilities' => $matches,
            'activated' => $activated,
        ];
    }
}
