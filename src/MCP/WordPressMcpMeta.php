<?php

/**
 * MCP-related WordPress ability meta helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads MCP public flags and ability annotations.
 */
final class WordPressMcpMeta {
    /**
     * Whether ability meta marks the ability as public for MCP default-server access.
     *
     * @param array<array-key, mixed> $meta Ability meta.
     */
    public static function is_public(array $meta): bool {
        $mcp = $meta['mcp'] ?? null;

        if (!is_array($mcp)) {
            return false;
        }

        return true === ($mcp['public'] ?? false);
    }

    /**
     * Normalize ability annotations into MCP tool annotation fields.
     *
     * @param array<array-key, mixed> $meta Ability meta.
     * @return array{readonly: bool|null, destructive: bool|null, requires_approval: bool|null}
     */
    public static function annotations(array $meta): array {
        $raw = $meta['annotations'] ?? null;
        $annotations = is_array($raw) ? $raw : [];

        return [
            'readonly' => self::optional_bool($annotations, 'readonly'),
            'destructive' => self::optional_bool($annotations, 'destructive'),
            'requires_approval' => self::optional_bool($annotations, 'requires_approval'),
        ];
    }

    /**
     * @param object $ability Ability instance.
     */
    public static function ability_is_public(object $ability): bool {
        return self::is_public(self::ability_meta($ability));
    }

    /**
     * @param object $ability Ability instance.
     * @return array<array-key, mixed>
     */
    public static function ability_meta(object $ability): array {
        if (!method_exists($ability, 'get_meta')) {
            return [];
        }

        $meta = $ability->get_meta();

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<array-key, mixed> $source Annotation map.
     */
    private static function optional_bool(array $source, string $key): ?bool {
        if (!array_key_exists($key, $source)) {
            return null;
        }

        $value = $source[$key];

        return is_bool($value) ? $value : (bool) $value;
    }
}
