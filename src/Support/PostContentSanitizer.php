<?php

/**
 * Post content sanitization helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Preserves serialized block markup while keeping classic HTML on the normal KSES path.
 */
final class PostContentSanitizer
{
    public static function for_staged_update(string $content): string
    {
        if (self::looks_like_block_markup($content)) {
            return $content;
        }

        return wp_kses_post($content);
    }

    private static function looks_like_block_markup(string $content): bool
    {
        return str_contains($content, '<!-- wp:') || str_contains($content, '<!-- /wp:');
    }
}
