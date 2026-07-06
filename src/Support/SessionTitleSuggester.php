<?php

/**
 * Session title suggestion helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds concise session titles from the first meaningful prompt.
 */
final class SessionTitleSuggester {
    /**
     * @param array<string, mixed> $session
     */
    public function suggest(string $message, array $session): ?string {
        if (!$this->is_default_title((string) ($session['title'] ?? ''))) {
            return null;
        }

        $base = $this->message_title($message);

        if (null === $base) {
            return null;
        }

        $focus = $this->focus_title((int) ($session['focus_post_id'] ?? 0), $base);
        $title = null === $focus ? $base : $focus . ': ' . $base;

        return mb_substr($title, 0, 80, 'UTF-8');
    }

    private function is_default_title(string $title): bool {
        $normalized = strtolower(trim($title));

        return '' === $normalized || in_array($normalized, ['new session', 'untitled session'], true);
    }

    private function message_title(string $message): ?string {
        $message = trim($message);

        if ('' === $message || str_starts_with($message, '/')) {
            return null;
        }

        $message = preg_replace('/https?:\/\/\S+/i', '', $message);
        $message = preg_replace('/\s+/', ' ', is_string($message) ? $message : '');
        $message = trim((string) $message, " \t\n\r\0\x0B.,!?;:\"'`");

        if ('' === $message) {
            return null;
        }

        $message = $this->remove_polite_prefix($message);
        $words = preg_split('/\s+/', $message);
        $words = is_array($words) ? array_slice($words, 0, 9) : [$message];

        return $this->title_case(trim(implode(' ', $words)));
    }

    private function remove_polite_prefix(string $message): string {
        $cleaned = preg_replace(
            '/^(please\s+|can you\s+|could you\s+|would you\s+|help me\s+|i need you to\s+)/i',
            '',
            $message,
        );

        return trim(is_string($cleaned) ? $cleaned : $message);
    }

    private function focus_title(int $post_id, string $base): ?string {
        if ($post_id <= 0) {
            return null;
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post || !current_user_can('read_post', $post_id)) {
            return null;
        }

        $title = trim(get_the_title($post));

        if ('' === $title || str_contains(strtolower($base), strtolower($title))) {
            return null;
        }

        return mb_substr($title, 0, 32, 'UTF-8');
    }

    private function title_case(string $title): string {
        $words = preg_split('/\s+/', $title);

        if (!is_array($words)) {
            return $title;
        }

        return implode(' ', array_map([$this, 'title_word'], $words));
    }

    private function title_word(string $word): string {
        if (strtoupper($word) === $word && strlen($word) > 1) {
            return $word;
        }

        return ucfirst(strtolower($word));
    }
}
