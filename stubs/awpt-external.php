<?php

/**
 * External API stubs for static analysis.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace {
    /**
     * @param array<string, mixed> $args
     */
    function wp_register_ability(string $name, array $args): void {}

    /**
     * @param array<string, mixed> $args
     */
    function wp_register_ability_category(string $slug, array $args): void {}

    /**
     * @return array<string, mixed>
     */
    function wp_get_abilities(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    function wp_get_ability(string $name): ?array
    {
        return null;
    }
}

namespace Kucrut\Vite {
    /**
     * @param array<string, mixed> $options
     */
    function enqueue_asset(string $manifest_dir, string $entry, array $options = []): void {}
}