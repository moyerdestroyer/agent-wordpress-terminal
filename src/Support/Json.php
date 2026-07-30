<?php

/**
 * JSON decode helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Safe JSON decoding at mixed boundaries.
 */
final class Json {
    public static function decode_value(string $json): mixed {
        if ('' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
    }

    /**
     * Decode JSON into an associative array.
     *
     * @return array<string, mixed>
     */
    public static function decode_array(string $json): array {
        if ('' === $json) {
            return [];
        }

        return ArrayKey::as_map(self::decode_value($json));
    }
}
