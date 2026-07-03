<?php

/**
 * awpt/read-users ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns admin-visible user summaries for agent analysis.
 */
final class ReadUsers
{
    /**
     * Register the ability.
     */
    public function register(): void
    {
        wp_register_ability('awpt/read-users', [
            'label' => __('Read Users', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns WordPress user summaries without exposing emails or password data.',
                'agent-wordpress-terminal',
            ),
            'category' => 'awpt',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'role' => [
                        'type' => 'string',
                        'description' => __('Optional role filter.', 'agent-wordpress-terminal'),
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => __('Optional login/display-name search term.', 'agent-wordpress-terminal'),
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => __(
                            'Maximum users to return. Defaults to 20, maximum 100.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'users' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'roles' => ['type' => 'object'],
                ],
            ],
            'permission_callback' => [$this, 'can_read'],
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
     * Permission check.
     *
     * @param array<string, mixed> $input Ability input.
     */
    public function can_read(array $input): bool
    {
        return current_user_can('list_users');
    }

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
        $role = sanitize_key((string) ($input['role'] ?? ''));
        $search = sanitize_text_field((string) ($input['search'] ?? ''));
        $args = [
            'number' => $limit,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => 'all',
        ];

        if ('' !== $role) {
            $args['role'] = $role;
        }

        if ('' !== $search) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'display_name', 'user_nicename'];
        }

        $query = new \WP_User_Query($args);
        $users = array_map([$this, 'normalize_user'], $query->get_results());

        return [
            'users' => $users,
            'count' => count($users),
            'total' => (int) $query->get_total(),
            'roles' => $this->role_counts(),
        ];
    }

    /**
     * Normalize a user without secret or contact fields.
     *
     * @param \WP_User $user User object.
     * @return array<string, mixed>
     */
    private function normalize_user(\WP_User $user): array
    {
        return [
            'id' => (int) $user->ID,
            'login' => $user->user_login,
            'display_name' => $user->display_name,
            'roles' => array_values($user->roles),
            'registered' => $user->user_registered,
            'post_count' => count_user_posts((int) $user->ID),
        ];
    }

    /**
     * Return user counts by role.
     *
     * @return array<array-key, int>
     */
    private function role_counts(): array
    {
        $counts = count_users();
        $roles = $counts['avail_roles'];

        return array_map('intval', $roles);
    }
}
