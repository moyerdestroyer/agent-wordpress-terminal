<?php

/**
 * Safe post meta exposure for read-content.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Filters and sanitizes post meta for agent-readable output.
 */
final class ReadablePostMeta
{
    private const VALUE_MAX_CHARS = 4_096;

    private PostMetaKeyPolicy $policy;

    public function __construct(?PostMetaKeyPolicy $policy = null)
    {
        $this->policy = $policy ?? new PostMetaKeyPolicy();
    }

    /**
     * @return array<string, mixed>
     */
    public function for_post(int $post_id): array
    {
        $meta = [];
        $raw = get_post_meta($post_id);

        if (!is_array($raw)) {
            return $meta;
        }

        foreach ($raw as $key => $values) {
            if (!is_string($key) || !$this->policy->is_exposed($key)) {
                continue;
            }

            if (!is_array($values) || [] === $values) {
                continue;
            }

            $meta[$key] = 1 === count($values)
                ? $this->sanitize_value(maybe_unserialize((string) $values[0]))
                : array_map(fn(mixed $value): mixed => $this->sanitize_value(maybe_unserialize(
                    (string) $value,
                )), $values);
        }

        return $meta;
    }

    private function sanitize_value(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value, 'UTF-8') > self::VALUE_MAX_CHARS) {
            return mb_substr($value, 0, self::VALUE_MAX_CHARS - 3, 'UTF-8') . '…';
        }

        if (is_array($value)) {
            $encoded = (string) wp_json_encode($value);

            if (mb_strlen($encoded, 'UTF-8') > self::VALUE_MAX_CHARS) {
                return mb_substr($encoded, 0, self::VALUE_MAX_CHARS - 3, 'UTF-8') . '…';
            }
        }

        return $value;
    }
}
