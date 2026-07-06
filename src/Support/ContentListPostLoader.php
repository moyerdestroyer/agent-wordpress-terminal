<?php

/**
 * Lightweight post row loading for content lists.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Loads list-card post fields without relying on full post_content hydration.
 */
final class ContentListPostLoader
{
    /**
     * @param list<int> $post_ids
     * @return list<\WP_Post>
     */
    public function load(array $post_ids): array
    {
        $post_ids = array_values(array_filter(array_map('intval', $post_ids), static fn(int $id): bool => $id > 0));

        if ([] === $post_ids) {
            return [];
        }

        $posts = [];

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);

            if ($post instanceof \WP_Post) {
                $posts[] = $post;
            }
        }

        return $posts;
    }
}
