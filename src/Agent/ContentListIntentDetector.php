<?php

/**
 * Detects list-content intent from user messages.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Maps natural-language browse/list requests to list-content arguments.
 */
final class ContentListIntentDetector
{
    public function should_auto_list(string $message): bool
    {
        $normalized = strtolower($message);

        if ($this->looks_like_specific_content_lookup($normalized)) {
            return false;
        }

        if (!preg_match(
            '/\b('
            . 'how many|how much|count|number of|total|list|show|recent|latest|browse|inventory|'
            . 'what posts|what pages|authored by|written by|by author|my posts|my pages|drafts'
            . ')\b/',
            $normalized,
        )) {
            return false;
        }

        foreach (['post', 'page', 'content', 'article', 'draft', 'template', 'block', 'author'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return (bool) preg_match(
            '/\b(how many|count|number of|total|list|show|recent)\b.*\b(site|wordpress|blog)\b/',
            $normalized,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments_for_message(string $message): array
    {
        $normalized = strtolower($message);
        $arguments = ['limit' => 20];

        if (str_contains($normalized, 'page') && !str_contains($normalized, 'post')) {
            $arguments['post_type'] = 'page';
        } elseif (str_contains($normalized, 'template')) {
            $arguments['post_type'] = 'wp_template';
        } elseif (str_contains($normalized, 'block')) {
            $arguments['post_type'] = 'wp_block';
        } elseif (str_contains($normalized, 'attachment') || str_contains($normalized, 'media')) {
            $arguments['post_type'] = 'attachment';
        } elseif (
            str_contains($normalized, 'content')
            && !str_contains($normalized, 'post')
            && !str_contains($normalized, 'page')
        ) {
            $arguments['post_type'] = 'all';
        } else {
            $arguments['post_type'] = 'post';
        }

        if (str_contains($normalized, 'draft')) {
            $arguments['status'] = 'draft';
        }

        if (preg_match('/\b(authored by|written by|by author|my posts|my pages)\b/', $normalized)) {
            $arguments['author'] = (string) get_current_user_id();
        }

        if (preg_match('/\b(recent|latest|newest)\b/', $normalized)) {
            $arguments['orderby'] = 'date';
        }

        if (preg_match('/\b(alphabet|title)\b/', $normalized)) {
            $arguments['orderby'] = 'title';
            $arguments['order'] = 'ASC';
        }

        return $arguments;
    }

    private function looks_like_specific_content_lookup(string $normalized): bool
    {
        if (preg_match('/\b(list|show all|how many|count|recent|latest|browse|inventory)\b/', $normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(find|locate|open|edit|update|change|preview|focus on|read|look at)\b/',
            $normalized,
        );
    }
}
