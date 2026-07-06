<?php

/**
 * Content search result formatting.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Deduplicates and formats readable search results.
 */
final class ContentSearchResultSet
{
    /**
     * @param array<int, array<string, mixed>> $results
     */
    public function add(array &$results, \WP_Post $post, string $matched_by, int $limit): void
    {
        if (count($results) >= $limit || !current_user_can('read_post', $post->ID)) {
            return;
        }

        if (array_key_exists($post->ID, $results)) {
            return;
        }

        $results[$post->ID] = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'url' => get_permalink($post),
            'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
            'matched_by' => $matched_by,
        ];
    }
}
