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
final class PostContentSanitizer {
    public static function for_staged_update(string $content): string {
        $content = self::unwrap_transport($content);

        if (self::looks_like_block_markup($content)) {
            return $content;
        }

        return wp_kses_post($content);
    }

    /**
     * Long-form tool arguments occasionally wrap a complete Gutenberg document
     * in a transport envelope. Remove only an envelope around the whole value;
     * literal CDATA or fences inside page copy remain untouched.
     */
    public static function unwrap_transport(string $content): string {
        $trimmed = trim($content);
        $matches = [];

        if (preg_match('/\A<!\[CDATA\[\s*(.*?)\s*\]\]>\z/s', $trimmed, $matches)) {
            return trim($matches[1] ?? '');
        }

        if (preg_match('/\A```(?:html|wordpress|gutenberg)?\s*\R(.*?)\R```\z/is', $trimmed, $matches)) {
            return trim($matches[1] ?? '');
        }

        return $content;
    }

    private static function looks_like_block_markup(string $content): bool {
        return str_contains($content, '<!-- wp:') || str_contains($content, '<!-- /wp:');
    }
}
