<?php

/**
 * Content list filter normalization.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds and normalizes filters for content listing queries.
 */
final class ContentListFilters {
    /**
     * @var list<string>
     */
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /**
     * @var list<string>
     */
    private const ORDERBY_FIELDS = ['modified', 'date', 'title', 'author', 'type'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function from_input(array $input): array {
        $author = sanitize_text_field((string) ($input['author'] ?? ''));
        $search = sanitize_text_field((string) ($input['search'] ?? ''));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $include_totals = $this->resolve_include_flag(
            $input['include_totals'] ?? null,
            '' === $author && '' === $search && 0 === $offset,
        );
        $include_total = $this->resolve_include_flag($input['include_total'] ?? null, '' === $author && '' === $search);

        return [
            'post_type' => sanitize_key((string) ($input['post_type'] ?? 'post')),
            'status' => sanitize_key((string) ($input['status'] ?? '')),
            'author' => $author,
            'author_id' => $this->resolve_author_id($author),
            'search' => $search,
            'orderby' => $this->resolve_orderby((string) ($input['orderby'] ?? 'modified')),
            'order' => $this->resolve_order((string) ($input['order'] ?? 'DESC')),
            'offset' => $offset,
            'limit' => max(1, min(100, (int) ($input['limit'] ?? 20))),
            'include_totals' => $include_totals,
            'include_total' => $include_total,
        ];
    }

    /**
     * @return list<string>
     */
    public function resolve_statuses(string $status_filter): array {
        if ('' === $status_filter || !in_array($status_filter, self::STATUSES, true)) {
            return self::STATUSES;
        }

        return [$status_filter];
    }

    private function resolve_include_flag(mixed $requested, bool $default): bool {
        if (is_bool($requested)) {
            return $requested;
        }

        if (is_numeric($requested)) {
            return (bool) (int) $requested;
        }

        if (is_string($requested)) {
            $normalized = strtolower(trim($requested));

            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function resolve_orderby(string $requested): string {
        $requested = sanitize_key($requested);

        return in_array($requested, self::ORDERBY_FIELDS, true) ? $requested : 'modified';
    }

    private function resolve_order(string $requested): string {
        return 'ASC' === strtoupper($requested) ? 'ASC' : 'DESC';
    }

    private function resolve_author_id(string $author): int {
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

    private function author_display_name(int $author_id): string {
        if ($author_id <= 0 || !function_exists('get_userdata')) {
            return '';
        }

        $user = get_userdata($author_id);

        return $user instanceof \WP_User ? $user->display_name : '';
    }

    /**
     * @param list<string> $post_types
     * @return array{by_status: array<string, int>, by_type: array<string, int>}
     */
}
