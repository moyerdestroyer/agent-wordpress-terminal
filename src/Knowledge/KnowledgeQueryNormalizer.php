<?php

/**
 * Query normalization for Knowledge auto-retrieval.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Turns chatty admin messages into denser retrieval queries.
 *
 * Only language-level expansions (not theme-specific product names).
 */
final class KnowledgeQueryNormalizer {
    /**
     * Prefer denser retrieval terms for common admin phrasing.
     */
    public function for_retrieval(string $query): string {
        $query = trim($query);

        if ('' === $query) {
            return '';
        }

        $lower = mb_strtolower($query);
        $boost = [];

        // Generic English ↔ domain language only. No theme slugs or product class names.
        $map = [
            'sticky' => ['sticky', 'position'],
            'toc' => ['toc', 'table of contents'],
            'table of contents' => ['toc', 'table of contents'],
            'sidebar' => ['sidebar', 'side navigation', 'navigation'],
            'sidenav' => ['sidebar', 'side navigation'],
            'editor' => ['editor', 'frontend', 'live'],
            'frontend' => ['frontend', 'live', 'css'],
            'live page' => ['frontend', 'css', 'layout'],
            'css' => ['css', 'scss', 'styles'],
            'scss' => ['scss', 'css', 'styles'],
            'stylesheet' => ['css', 'scss', 'styles'],
            'documentation' => ['documentation', 'docs', 'guide', 'reference'],
            'docs' => ['docs', 'documentation', 'guide', 'reference'],
            'layout' => ['layout', 'columns', 'template'],
            'spacing' => ['spacing', 'padding', 'margin'],
        ];

        foreach ($map as $needle => $terms) {
            if (str_contains($lower, $needle)) {
                $boost = [...$boost, ...$terms];
            }
        }

        if ([] === $boost) {
            return $query;
        }

        $boost = array_values(array_unique($boost));

        return trim($query . ' ' . implode(' ', $boost));
    }
}
