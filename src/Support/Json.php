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
    /**
     * Decode JSON into an associative array.
     *
     * @return array<string, mixed>
     */
    public static function decode_array(string $json): array {
        if ('' === $json) {
            return [];
        }

        return ArrayKey::as_map(json_decode($json, true));
    }
}
