<?php

/**
 * Sanitizes staged block attribute payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Normalizes arbitrary block attribute maps before action storage.
 */
final class BlockAttrsPayloadSanitizer {
    /**
     * @param array<array-key, mixed> $attrs
     * @return array<string, mixed>
     */
    public function sanitize_map(array $attrs): array {
        $clean = [];

        foreach ($attrs as $key => $value) {
            if (!is_string($key) || '' === $key) {
                continue;
            }

            $clean[$key] = $this->sanitize_value($value);
        }

        return $clean;
    }

    private function sanitize_value(mixed $value): mixed {
        if (is_array($value)) {
            return $this->sanitize_array_value($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function sanitize_array_value(array $value): array {
        $clean = [];

        foreach ($value as $key => $item) {
            $clean[$key] = $this->sanitize_value($item);
        }

        return $clean;
    }
}
