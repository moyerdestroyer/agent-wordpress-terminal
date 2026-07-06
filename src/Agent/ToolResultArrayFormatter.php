<?php

/**
 * Shared helpers for tool output formatter classes.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts mixed tool output fragments into safe display primitives.
 */
final class ToolResultArrayFormatter
{
    /**
     * @return list<array<array-key, mixed>>
     */
    public function list_items(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    public function excerpt(string $text, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 3, 'UTF-8')) . '...';
    }
}
