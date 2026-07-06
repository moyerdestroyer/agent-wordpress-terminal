<?php

/**
 * Maps WordPress posts to knowledge source records.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts WP_Post objects into knowledge source arrays.
 */
final class KnowledgePostSourceMapper
{
    private const CORE_POST_TYPE = 'wp_knowledge';
    private const CORE_TAXONOMY = 'wp_knowledge_type';
    private const LEGACY_POST_TYPE = 'wp_guideline';
    private const LEGACY_TAXONOMY = 'wp_guideline_type';

    /**
     * @return array<string, mixed>
     */
    public function from_post(\WP_Post $post, string $kind, string $taxonomy): array
    {
        $types = '' !== $taxonomy ? wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'slugs']) : [];
        $types = is_wp_error($types) || !is_array($types) ? [] : array_values(array_map('strval', $types));
        $content = $post->post_content;
        $excerpt = wp_strip_all_tags($post->post_excerpt);

        if ('' !== $excerpt) {
            $content = trim($content . "\n\n" . $excerpt);
        }

        return [
            'kind' => $kind,
            'source_id' => $kind . ':' . $post->ID,
            'post_id' => $post->ID,
            'label' => $this->label($post),
            'uri' => get_permalink($post),
            'content' => $content,
            'modified_at' => $post->post_modified_gmt,
            'types' => $types,
            'metadata' => [
                'post_type' => $post->post_type,
                'status' => $post->post_status,
                'knowledge_types' => $types,
            ],
        ];
    }

    public function taxonomy_for_post_type(string $post_type): string
    {
        return match ($post_type) {
            self::CORE_POST_TYPE => self::CORE_TAXONOMY,
            self::LEGACY_POST_TYPE => self::LEGACY_TAXONOMY,
            default => '',
        };
    }

    private function label(\WP_Post $post): string
    {
        $title = get_the_title($post);

        if ('' !== $title) {
            return $title;
        }

        return sprintf('%s #%d', $post->post_type, $post->ID);
    }
}
