<?php

/**
 * Content list inventory totals.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Computes site-wide totals for content listing responses.
 */
final class ContentListTotals
{
    /**
     * @var list<string>
     */
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    private ContentSearchTypes $types;

    public function __construct(?ContentSearchTypes $types = null)
    {
        $this->types = $types ?? new ContentSearchTypes();
    }

    /**
     * @param list<string> $post_types
     * @return array<string, int>
     */
    public function by_status(array $post_types): array
    {
        if (!function_exists('wp_count_posts')) {
            return [];
        }

        $totals = [];

        foreach ($post_types as $post_type) {
            $counts = wp_count_posts($post_type);

            foreach (self::STATUSES as $status) {
                $totals[$status] = ($totals[$status] ?? 0) + (int) ($counts->{$status} ?? 0);
            }
        }

        return array_filter($totals, static fn(int $count): bool => $count > 0);
    }

    /**
     * @return array<string, int>
     */
    public function by_type(): array
    {
        return $this->inventory()['by_type'];
    }

    /**
     * @param list<string> $post_types
     * @return array{by_status: array<string, int>, by_type: array<string, int>}
     */
    public function inventory(?array $post_types = null): array
    {
        if (!function_exists('wp_count_posts')) {
            return [
                'by_status' => [],
                'by_type' => [],
            ];
        }

        $types = null !== $post_types && [] !== $post_types ? $post_types : $this->types->from_requested('');
        $by_status = [];
        $by_type = [];

        foreach ($types as $post_type) {
            $counts = wp_count_posts($post_type);
            $type_total = 0;

            foreach (self::STATUSES as $status) {
                $count = (int) ($counts->{$status} ?? 0);
                $type_total += $count;

                if ($count > 0) {
                    $by_status[$status] = ($by_status[$status] ?? 0) + $count;
                }
            }

            if ($type_total > 0) {
                $by_type[$post_type] = $type_total;
            }
        }

        return [
            'by_status' => $by_status,
            'by_type' => $by_type,
        ];
    }
}
