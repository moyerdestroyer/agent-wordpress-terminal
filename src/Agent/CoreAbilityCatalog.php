<?php

/**
 * Compatibility access to the WordPress Abilities catalog.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Uses the filtered WordPress 7.1 catalog API while remaining compatible with
 * the argument-free catalog shipped by earlier Abilities API versions.
 */
final class CoreAbilityCatalog {
    /**
     * @param array<string, mixed> $args WordPress 7.1 catalog query arguments.
     * @return array<array-key, \WP_Ability>
     */
    public function all(array $args = []): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        try {
            $function = new \ReflectionFunction('wp_get_abilities');
            $abilities =
                [] === $args || $function->getNumberOfParameters() < 1 ? $function->invoke() : $function->invoke($args);
        } catch (\ReflectionException) {
            return [];
        }

        if (!is_array($abilities)) {
            return [];
        }

        /** @var array<array-key, \WP_Ability> $abilities */
        return $abilities;
    }
}
