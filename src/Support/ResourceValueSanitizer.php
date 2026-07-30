<?php

/**
 * Bounded recursive values for generic WordPress resource proposals.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

final class ResourceValueSanitizer {
    private const MAX_DEPTH = 8;
    private const MAX_ITEMS = 250;
    private const MAX_STRING = 50_000;

    /**
     * Sanitize the top-level JSON object used by resource operations.
     *
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    public function sanitize_object(array $value): array {
        $clean = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $clean_key = sanitize_key($key);

            if ('' !== $clean_key) {
                $clean[$clean_key] = $this->sanitize($item, 1);
            }
        }

        return array_slice($clean, 0, self::MAX_ITEMS, true);
    }

    public function sanitize(mixed $value, int $depth = 0): mixed {
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        if (is_string($value)) {
            return mb_substr(wp_unslash($value), 0, self::MAX_STRING, 'UTF-8');
        }

        if (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $clean = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if (++$count > self::MAX_ITEMS) {
                break;
            }

            $clean_key = is_string($key) ? sanitize_key($key) : (int) $key;

            if (is_string($key) && '' === $clean_key) {
                continue;
            }

            $clean[$clean_key] = $this->sanitize($item, $depth + 1);
        }

        return $clean;
    }
}
