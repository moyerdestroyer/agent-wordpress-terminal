<?php

/**
 * Verified design evidence attached to staged actions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Adds compact server-derived composition evidence without changing action schema.
 */
final class CompositionActionContext {
    /**
     * @var list<string>
     */
    private const DESIGN_OPERATIONS = [
        ActionOperations::CONTENT_UPDATE,
        ActionOperations::BLOCK_ATTRS_UPDATE,
        ActionOperations::BLOCK_INSERT,
        ActionOperations::BLOCK_REMOVE,
        ActionOperations::PATTERN_INSERT,
        ActionOperations::NEW_POST,
        ActionOperations::TEMPLATE_UPDATE,
        ActionOperations::GLOBAL_STYLES_UPDATE,
        ActionOperations::GLOBAL_STYLES_CREATE,
        ActionOperations::CUSTOM_CSS_UPDATE,
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function enrich(array $payload): array {
        $operation = sanitize_key((string) ($payload['operation'] ?? ''));

        if (!in_array($operation, self::DESIGN_OPERATIONS, true)) {
            return $payload;
        }

        $design = new SiteDesignContext();
        $site = $design->resolve();
        $pattern_name = sanitize_text_field((string) ($payload['pattern_name'] ?? ''));
        $pattern_source = sanitize_key((string) ($payload['pattern_source'] ?? 'registered'));
        $fallback_reason = sanitize_textarea_field((string) ($payload['pattern_fallback_reason'] ?? ''));
        $pattern_relevant =
            '' !== $pattern_name || '' !== $fallback_reason || ActionOperations::NEW_POST === $operation;
        $pattern_owner = '' !== $pattern_name
            ? $design->pattern_owner($pattern_name, $pattern_source)
            : ($pattern_relevant ? 'custom' : 'not_applicable');
        $fallback_used =
            $pattern_relevant && !in_array($pattern_owner, ['active_theme', 'parent_theme', 'reusable'], true);

        $payload['composition_context'] = [
            'policy' => 'theme_native_preferred',
            'theme_name' => $site['theme_name'],
            'stylesheet' => $site['stylesheet'],
            'template' => $site['template'],
            'pattern_name' => $pattern_name,
            'pattern_owner' => $pattern_owner,
            'fallback_used' => $fallback_used,
            'fallback_reason' => $fallback_reason,
        ];

        $trace = is_array($payload['decision_trace'] ?? null)
            ? array_values(array_map('strval', $payload['decision_trace']))
            : [];
        $trace[] = sprintf(
            'Design basis: active theme %s (%s); theme-native composition preferred.',
            $site['theme_name'],
            $site['stylesheet'],
        );

        if ('' !== $pattern_name) {
            $trace[] = sprintf('Pattern basis: %s (%s).', $pattern_name, str_replace('_', ' ', $pattern_owner));
        } elseif ('' !== $fallback_reason || ActionOperations::NEW_POST === $operation) {
            $trace[] = '' !== $fallback_reason
                ? sprintf('Custom composition fallback: %s', $fallback_reason)
                : 'Custom composition; no registered pattern selected.';
        }

        $payload['decision_trace'] = array_values(array_unique(array_filter(array_map('trim', $trace))));

        return $payload;
    }
}
