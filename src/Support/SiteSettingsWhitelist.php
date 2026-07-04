<?php

/**
 * Allowed WordPress options for staged site settings updates.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whitelist and sanitization for site settings action payloads.
 */
final class SiteSettingsWhitelist
{
    /**
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'blogname',
        'blogdescription',
        'blog_public',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'posts_per_page',
        'default_comment_status',
        'default_ping_status',
        'require_name_email',
        'comment_registration',
        'thread_comments',
        'page_comments',
        'comments_per_page',
        'permalink_structure',
        'category_base',
        'tag_base',
    ];

    /**
     * @param array<array-key, mixed> $raw_settings
     * @return array<string, string|int>
     */
    public function sanitize_map(array $raw_settings): array
    {
        $settings = [];

        foreach (array_keys($raw_settings) as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $sanitized = $this->sanitize_setting($key, $raw_settings[$key]);

            if (null !== $sanitized) {
                $settings[$key] = $sanitized;
            }
        }

        return $settings;
    }

    public function is_allowed_key(string $key): bool
    {
        return in_array($key, self::ALLOWED_KEYS, true);
    }

    private function sanitize_setting(string $key, mixed $value): string|int|null
    {
        return match ($key) {
            'blogname', 'blogdescription', 'category_base', 'tag_base' => sanitize_text_field((string) $value),
            'blog_public',
            'require_name_email',
            'comment_registration',
            'thread_comments',
            'page_comments',
                => $this->truthy($value) ? 1 : 0,
            'show_on_front' => in_array((string) $value, ['posts', 'page'], true) ? (string) $value : null,
            'page_on_front', 'page_for_posts' => $this->sanitize_page_id($value),
            'posts_per_page' => max(1, min(100, $this->to_absint($value))),
            'comments_per_page' => max(1, min(500, $this->to_absint($value))),
            'default_comment_status', 'default_ping_status' => in_array((string) $value, ['open', 'closed'], true)
                ? (string) $value
                : null,
            'permalink_structure' => $this->sanitize_permalink_structure($value),
            default => null,
        };
    }

    private function sanitize_page_id(mixed $value): int
    {
        $page_id = $this->to_absint($value);

        return $page_id > 0 && get_post($page_id) instanceof \WP_Post ? $page_id : 0;
    }

    private function to_absint(mixed $value): int
    {
        return absint(is_scalar($value) ? $value : 0);
    }

    private function sanitize_permalink_structure(mixed $value): ?string
    {
        $structure = sanitize_text_field((string) $value);

        if ('' === $structure) {
            return '';
        }

        return preg_match('/^\/[A-Za-z0-9_\/%\-]+\/$/', $structure) ? $structure : null;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }
}
