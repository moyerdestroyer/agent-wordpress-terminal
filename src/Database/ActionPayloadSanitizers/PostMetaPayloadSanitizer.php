<?php

/**
 * Sanitizes post meta maps in action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database\ActionPayloadSanitizers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Normalizes post meta change maps on staged actions.
 */
final class PostMetaPayloadSanitizer
{
    /**
     * @param array<array-key, mixed> $meta
     * @return array<string, string|int|float|bool>
     */
    public function sanitize_map(array $meta): array
    {
        $clean = [];

        foreach ($meta as $key => $value) {
            $meta_key = sanitize_key((string) $key);

            if ('' === $meta_key) {
                continue;
            }

            if (is_bool($value)) {
                $clean[$meta_key] = $value;
                continue;
            }

            if (is_int($value)) {
                $clean[$meta_key] = $value;
                continue;
            }

            if (is_float($value)) {
                $clean[$meta_key] = $value;
                continue;
            }

            $clean[$meta_key] = sanitize_text_field((string) $value);
        }

        return $clean;
    }
}
