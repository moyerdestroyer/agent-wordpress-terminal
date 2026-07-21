<?php

/**
 * Extracts literal URLs from free-text message content.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Pure text-extraction logic, kept free of WordPress/database dependencies so it can
 * be unit tested directly.
 */
final class MessageUrlExtractor {
    /**
     * Extract http(s) URLs from a block of text, trimming trailing prose punctuation
     * (e.g. a period or closing parenthesis that isn't actually part of the URL).
     *
     * @return list<string>
     */
    public function extract(string $text): array {
        $matches = [];

        if (!preg_match_all('/https?:\/\/[^\s<>"]+/i', $text, $matches)) {
            return [];
        }

        return array_values(array_map(static fn(string $url): string => rtrim($url, ".,;:!?)]}'\""), $matches[0]));
    }
}
