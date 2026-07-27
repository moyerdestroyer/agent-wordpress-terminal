<?php

/**
 * Soft fallback policy for substantial page composition.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Allows non-theme composition while requiring a concise reason when native options exist.
 */
final class PatternFallbackPolicy {
    public function validate(
        PatternCatalog $patterns,
        string $post_type,
        string $pattern_owner,
        string $reason,
    ): ?\WP_Error {
        if (
            new SiteDesignContext()->is_site_native_owner($pattern_owner)
            || '' !== trim($reason)
            || !$patterns->has_site_native_patterns($post_type)
        ) {
            return null;
        }

        $preferred = array_values(array_filter(
            $patterns->list('', 12, $post_type),
            static fn(array $item): bool => in_array(
                (string) ($item['owner'] ?? ''),
                ['active_theme', 'parent_theme', 'reusable'],
                true,
            ),
        ));

        return new \WP_Error(
            'awpt_pattern_fallback_reason_required',
            __(
                'Theme-native or site-owned patterns are available. Use one, or briefly explain why a Core, plugin, or custom composition better fits this draft.',
                'agent-wordpress-terminal',
            ),
            [
                'status' => 400,
                'preferred_patterns' => array_slice($preferred, 0, 8),
                'recommended_next_tools' => [
                    [
                        'tool' => 'awpt/list-patterns',
                        'input' => ['search' => '', 'max' => 24, 'post_type' => $post_type],
                    ],
                ],
                'recovery' => __(
                    'Choose and read a suitable preferred pattern, or resubmit the same composition with pattern_fallback_reason describing the concrete mismatch.',
                    'agent-wordpress-terminal',
                ),
            ],
        );
    }
}
