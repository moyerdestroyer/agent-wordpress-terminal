<?php

/**
 * Array key normalization helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Narrows array-key maps at mixed boundaries without mixed local assignments.
 */
final class ArrayKey {
    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    public static function string_map(array $row): array {
        $mapped = [];

        foreach (array_keys($row) as $key) {
            $mapped[(string) $key] = self::passthrough($row[$key] ?? null);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function as_map(mixed $value): array {
        return is_array($value) ? self::string_map($value) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function list_of_maps(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach (array_keys($value) as $key) {
            $row = self::as_map_or_null(self::passthrough($value[$key] ?? null));

            if (null !== $row) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public static function list_of_strings(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach (array_keys($value) as $key) {
            $string = self::as_string(self::passthrough($value[$key] ?? null));

            if (null !== $string) {
                $items[] = $string;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function as_map_or_null(mixed $value): ?array {
        return is_array($value) ? self::string_map($value) : null;
    }

    public static function as_string(mixed $value): ?string {
        return is_string($value) ? $value : null;
    }

    public static function as_int(mixed $value): int {
        return absint(is_scalar($value) ? $value : 0);
    }

    /**
     * WordPress REST-style boolean coercion for mixed request params.
     */
    public static function rest_bool(mixed $value): bool {
        if (is_bool($value) || is_int($value) || is_string($value)) {
            return (bool) rest_sanitize_boolean($value);
        }

        return false;
    }

    /**
     * Identity helper so mixed values can be threaded without a local mixed assignment.
     */
    public static function passthrough(mixed $value): mixed {
        return $value;
    }
}
