<?php

/**
 * Resolves share/preview page URLs to their underlying direct media URL.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Many "GIF link" shares (Tenor, Giphy, etc.) are actually HTML share/preview pages
 * rather than direct media files, even when their URL ends in `.gif`. This extracts
 * the real underlying media URL from the page's Open Graph / Twitter Card meta tags —
 * the same standard tags those pages already publish for link-preview cards — so AWPT
 * can import the actual asset instead of failing with a generic upload error.
 *
 * Pure string-in/string-out logic with no WordPress or network dependency, so it can be
 * unit tested directly.
 */
final class OpenGraphMediaUrlExtractor {
    /**
     * Meta tag properties to check, in preference order. `og:image` is checked before
     * `og:video` because a page offering both usually represents the same clip, and a
     * still/animated image is normally the more useful default for "add a GIF" requests.
     *
     * @var list<string>
     */
    private const PROPERTIES = [
        'og:image:secure_url',
        'og:image',
        'twitter:image',
        'og:video:secure_url',
        'og:video',
    ];

    /**
     * Whether a chunk of downloaded content looks like an HTML document rather than a
     * binary media file.
     */
    public function looks_like_html(string $content): bool {
        return (bool) preg_match('/<!doctype\s+html|<html[\s>]/i', ltrim($content));
    }

    /**
     * Extract the first matching Open Graph / Twitter Card media URL from an HTML
     * document, or null if none of the known properties are present.
     */
    public function extract(string $html): ?string {
        foreach (self::PROPERTIES as $property) {
            $url = $this->find_meta_content($html, $property);

            if (null !== $url) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Find a `<meta property|name="..." content="...">` tag's content, tolerating
     * either attribute order.
     */
    private function find_meta_content(string $html, string $property): ?string {
        $escaped = preg_quote($property, '/');

        $patterns = [
            '/<meta[^>]+(?:property|name)=["\']' . $escaped . '["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $escaped . '["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return html_entity_decode($matches[1], ENT_QUOTES);
            }
        }

        return null;
    }
}
