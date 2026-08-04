<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Domain\DomainPackHealth;
use AWPT\Domain\DomainPackRegistry;

if (!defined('ABSPATH')) {
    exit();
}

/** Lists active and disabled theme Domain Packs. */
final class ListDomainPacks implements AbilityInterface {
    public function register(): void {
        /** @var array<string, mixed> $input_schema */
        $input_schema = ['type' => 'object', 'properties' => []];
        /** @var array<string, mixed> $output_schema */
        $output_schema = ['type' => 'object'];
        /** @var callable(array<string, mixed>): bool $permission_callback */
        $permission_callback = [$this, 'can_read'];
        /** @var callable(array<string, mixed>): (array<string, mixed>|\WP_Error) $execute_callback */
        $execute_callback = [$this, 'execute'];

        AbilityRegistrar::register([
            'name' => 'awpt/list-domain-packs',
            'label' => __('List Domain Packs', 'agent-wordpress-terminal'),
            'description' => __(
                'Lists theme-provided design and editorial expertise active in AWPT.',
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
        return [
            'packs' => DomainPackRegistry::instance()->status(),
            'health' => new DomainPackHealth()->report(),
            'policy' => 'Active packs guide domain work but never bypass AWPT evidence, staging, permissions, or approval.',
        ];
    }
}
