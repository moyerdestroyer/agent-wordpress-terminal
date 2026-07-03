<?php

/**
 * Typed REST request parameters.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

defined('ABSPATH') || exit();

/**
 * Extracts typed values from WP_REST_Request.
 */
final class RequestParams
{
    /**
     * @return array<string, mixed>
     */
    public static function object(\WP_REST_Request $request, string $key): array
    {
        $value = $request->get_param($key);

        return is_array($value) ? $value : [];
    }

    public static function string(\WP_REST_Request $request, string $key, string $default = ''): string
    {
        $value = $request->get_param($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(\WP_REST_Request $request, string $key): int
    {
        $value = $request->get_param($key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
