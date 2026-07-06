<?php

/**
 * Content list author resolution.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves author filters and display names for content listings.
 */
final class ContentListAuthorResolver
{
    public function resolve_id(string $author): int
    {
        if ('' === $author) {
            return 0;
        }

        if (ctype_digit($author)) {
            return (int) $author;
        }

        if (!function_exists('get_user_by')) {
            return 0;
        }

        foreach (['login', 'slug', 'display_name', 'email'] as $field) {
            $user = get_user_by($field, $author);

            if ($user instanceof \WP_User) {
                return (int) $user->ID;
            }
        }

        return 0;
    }

    public function display_name(int $author_id): string
    {
        if ($author_id <= 0 || !function_exists('get_userdata')) {
            return '';
        }

        $user = get_userdata($author_id);

        return $user instanceof \WP_User ? $user->display_name : '';
    }
}
