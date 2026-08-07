<?php

/**
 * Optional pattern-fallback telemetry fields for propose abilities.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Schema snippets and payload persistence for pattern fallback notes.
 * Codes are validated at stage time against session recommend evidence.
 */
final class PatternUnfitInput {
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function schema_properties(): array {
        return [
            'pattern_unfit_code' => [
                'type' => 'string',
                'description' => __(
                    'When composing without a recommended theme pattern: no_recommendations (only if recommend returned empty), explicit_bespoke, preservation_conflict, media_unavailable, or scope_mismatch. Invalid when recommendations were non-empty and code is no_recommendations.',
                    'agent-wordpress-terminal',
                ),
            ],
            'pattern_fallback_reason' => [
                'type' => 'string',
                'description' => __(
                    'Short note when composing without a theme pattern. Required honesty: do not claim an empty catalog when recommendations exist.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function persist_on_payload(array $payload, array $input): array {
        $unfit_code = sanitize_key((string) ($input['pattern_unfit_code'] ?? ''));
        $reason = sanitize_textarea_field((string) ($input['pattern_fallback_reason'] ?? ''));

        if ('' !== $unfit_code) {
            $payload['pattern_unfit_code'] = $unfit_code;
        }

        if ('' !== $reason) {
            $payload['pattern_fallback_reason'] = $reason;
        }

        return $payload;
    }
}
