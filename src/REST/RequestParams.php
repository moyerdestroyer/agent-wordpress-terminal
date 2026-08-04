<?php

/**
 * Typed REST request parameters.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Extracts typed values from WP_REST_Request.
 */
final class RequestParams {
    /**
     * @return array<array-key, mixed>
     */
    public static function object(\WP_REST_Request $request, string $key): array {
        $value = $request->get_param($key);

        return is_array($value) ? $value : [];
    }

    public static function string(\WP_REST_Request $request, string $key, string $default = ''): string {
        $value = $request->get_param($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(\WP_REST_Request $request, string $key): int {
        $value = $request->get_param($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function boolean(\WP_REST_Request $request, string $key, bool $default = false): bool {
        $value = $request->get_param($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return 1 === (int) $value;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return null === $parsed ? $default : $parsed;
        }

        return $default;
    }
}
