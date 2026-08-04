<?php

/**
 * Ordered server-side pattern composition.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

if (!defined('ABSPATH')) {
    exit();
}

/** Expands any ordered pattern list, then applies compact edits to the combined tree. */
final class PatternCompositionBuilder {
    /**
     * @param list<string> $pattern_names
     * @param array<array-key, mixed> $exact_replacements
     * @param array<array-key, mixed> $text_updates
     * @param array<array-key, mixed> $media_placements
     */
    public function build(
        array $pattern_names,
        array $exact_replacements = [],
        array $text_updates = [],
        array $media_placements = [],
    ): string|\WP_Error {
        if ([] === $pattern_names) {
            return new \WP_Error(
                'awpt_materialized_pattern_required',
                __(
                    'Materialized mode requires at least one exact registered pattern name.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 400],
            );
        }

        $parts = [];

        foreach ($pattern_names as $pattern_name) {
            $expanded = new PatternTemplateExpander()->expand($pattern_name);

            if (is_wp_error($expanded)) {
                return $expanded;
            }

            $parts[] = $expanded;
        }

        $content = implode("\n\n", $parts);
        $content = $this->apply_exact_replacements($content, $exact_replacements);

        if (is_wp_error($content)) {
            return $content;
        }

        $content = new PatternTextUpdater()->apply($content, $text_updates);

        if (is_wp_error($content)) {
            return $content;
        }

        return new PatternMediaPlacer()->apply($content, $media_placements);
    }

    /** @param array<array-key, mixed> $replacements */
    private function apply_exact_replacements(string $content, array $replacements): string|\WP_Error {
        foreach ($replacements as $index => $replacement) {
            if (!is_array($replacement)) {
                return new \WP_Error(
                    'awpt_invalid_content_replacement',
                    __('Pattern replacements must be objects.', 'agent-wordpress-terminal'),
                    ['status' => 400, 'replacement_index' => (int) $index],
                );
            }

            $search = (string) ($replacement['search'] ?? '');
            $replace = (string) ($replacement['replace'] ?? '');
            $expected = max(1, (int) ($replacement['expected_count'] ?? 1));
            $actual = '' !== $search ? substr_count($content, $search) : 0;

            if ($actual !== $expected) {
                return new \WP_Error(
                    'awpt_content_replacement_mismatch',
                    sprintf(
                        __(
                            'Replacement %1$d expected %2$d exact match(es), but found %3$d.',
                            'agent-wordpress-terminal',
                        ),
                        (int) $index + 1,
                        $expected,
                        $actual,
                    ),
                    [
                        'status' => 409,
                        'replacement_index' => (int) $index,
                        'expected' => $expected,
                        'actual' => $actual,
                    ],
                );
            }

            $count = 0;
            $content = str_replace($search, $replace, $content, $count);

            if ($count !== $expected) {
                return new \WP_Error(
                    'awpt_content_replacement_failed',
                    __('Could not apply an exact pattern replacement.', 'agent-wordpress-terminal'),
                    ['status' => 409],
                );
            }
        }

        return $content;
    }
}
