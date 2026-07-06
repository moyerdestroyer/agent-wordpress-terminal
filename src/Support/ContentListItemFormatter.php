<?php

/**
 * Content list item formatting.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Formats readable list items from WP_Post objects.
 */
final class ContentListItemFormatter
{
    private ContentListAuthorResolver $authors;

    public function __construct(?ContentListAuthorResolver $authors = null)
    {
        $this->authors = $authors ?? new ContentListAuthorResolver();
    }

    /**
     * @return array<string, mixed>
     */
    public function from_post(\WP_Post $post): array
    {
        $author_id = (int) $post->post_author;

        return [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'author_id' => $author_id,
            'author' => $this->authors->display_name($author_id),
            'created' => $post->post_date_gmt,
            'modified' => $post->post_modified_gmt,
            'excerpt' => $this->excerpt($post),
            'url' => get_permalink($post),
            'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
        ];
    }

    private function excerpt(\WP_Post $post): string
    {
        return trim($post->post_excerpt);
    }
}
