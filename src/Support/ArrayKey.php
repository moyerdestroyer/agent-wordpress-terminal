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
 * Narrows array-key maps at mixed boundaries.
 */
final class ArrayKey
{
    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    public static function string_map(array $row): array
    {
        $mapped = [];

        foreach ($row as $key => $value) {
            $mapped[(string) $key] = $value;
        }

        return $mapped;
    }
}
