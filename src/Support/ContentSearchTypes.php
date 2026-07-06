<?php

/**
 * Content search post type resolution.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves supported post type filters for AWPT search.
 */
final class ContentSearchTypes
{
    /**
     * @return list<string>
     */
    public function from_requested(string $requested): array
    {
        if ('' !== $requested) {
            $post_type = sanitize_key($requested);

            if ($this->exists($post_type)) {
                return [$post_type];
            }
        }

        return array_values(array_filter(
            ['post', 'page', 'wp_template', 'wp_template_part', 'wp_block', 'attachment'],
            $this->exists(...),
        ));
    }

    private function exists(string $post_type): bool
    {
        return '' !== $post_type && (!function_exists('post_type_exists') || post_type_exists($post_type));
    }
}
