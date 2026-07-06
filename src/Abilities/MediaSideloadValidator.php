<?php

/**
 * Validation rules for sideloading remote media.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Pure validation logic for `awpt/sideload-media`, kept free of WordPress file/HTTP
 * APIs so it can be unit tested without a full WordPress environment.
 */
final class MediaSideloadValidator {
    /**
     * File extensions AWPT will import. Deliberately conservative — WordPress's own
     * `wp_handle_sideload()` re-validates the actual downloaded file's real type on
     * top of this, but rejecting obviously-wrong URLs early gives a clearer error.
     *
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = ['gif', 'png', 'jpg', 'jpeg', 'webp', 'mp4', 'webm'];

    /**
     * Maximum accepted download size, in bytes.
     */
    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * Validate a media URL before download. Allows extensionless share/preview pages
     * so Open Graph resolution can run after the initial fetch.
     */
    public function validate_url(string $url): ?string {
        return $this->validate_url_scheme($url);
    }

    /**
     * Validate a resolved direct media URL (after OG extraction). Requires a supported extension.
     */
    public function validate_direct_media_url(string $url): ?string {
        $scheme_error = $this->validate_url_scheme($url);

        if (null !== $scheme_error) {
            return $scheme_error;
        }

        return $this->validate_extension($url);
    }

    /**
     * Validate URL scheme only.
     */
    public function validate_url_scheme(string $url): ?string {
        $url = trim($url);

        if ('' === $url) {
            return __('A media URL is required.', 'agent-wordpress-terminal');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return __('The media URL must start with http:// or https://.', 'agent-wordpress-terminal');
        }

        return null;
    }

    private function validate_extension(string $url): ?string {
        $extension = $this->extension_from_url($url);

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return sprintf(
                /* translators: 1: rejected file extension, 2: comma-separated allowed extensions */
                __(
                    'Unsupported media type "%1$s". Provide a direct link ending in one of: %2$s.',
                    'agent-wordpress-terminal',
                ),
                '' !== $extension ? $extension : '?',
                implode(', ', self::ALLOWED_EXTENSIONS),
            );
        }

        return null;
    }

    /**
     * Extract a lowercase file extension from a URL's path component.
     */
    public function extension_from_url(string $url): string {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Whether a downloaded file's size is within the accepted limit.
     */
    public function is_size_allowed(int $bytes): bool {
        return $bytes > 0 && $bytes <= self::MAX_BYTES;
    }
}
