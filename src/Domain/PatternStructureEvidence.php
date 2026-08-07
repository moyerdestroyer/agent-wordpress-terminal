<?php

/**
 * Session evidence that a pattern's structure was loaded before staging.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\PatternFirstPolicy;
use AWPT\Support\TurnToolEvidence;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Server-side pattern structure evidence for stage gates.
 *
 * Ollie-inspired rule: you cannot claim or stamp a pattern without having
 * loaded its structure (read-pattern or prepare-pattern-draft mode=pattern).
 */
final class PatternStructureEvidence {
    private PatternFirstPolicy $patterns;

    public function __construct(?PatternFirstPolicy $patterns = null) {
        $this->patterns = $patterns ?? new PatternFirstPolicy();
    }

    /**
     * @return list<array{tool: string, input: mixed, output: mixed, status: string}>
     */
    public function session_calls(int $session_id): array {
        return $this->patterns->session_tool_calls($session_id);
    }

    public function has_read(int $session_id, string $pattern_name): bool {
        $pattern_name = sanitize_text_field($pattern_name);

        if ($session_id <= 0 || '' === $pattern_name) {
            return false;
        }

        foreach ($this->session_calls($session_id) as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');
            $input = is_array($call['input'] ?? null) ? $call['input'] : [];
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];

            if ('awpt/read-pattern' === $tool) {
                $name = sanitize_text_field((string) (
                    $input['name']
                    ?? $input['pattern_name']
                    ?? $output['name']
                    ?? ''
                ));

                if ($name === $pattern_name) {
                    return true;
                }
            }

            if (
                'awpt/prepare-pattern-draft' === $tool
                || 'awpt/prepare-pattern-change' === $tool
            ) {
                $mode = sanitize_key((string) ($output['mode'] ?? ''));

                if (
                    'pattern' !== $mode
                    && PatternPreparationReceipt::MODE_REPLACE !== $mode
                    && PatternPreparationReceipt::MODE_INSERT !== $mode
                ) {
                    continue;
                }

                // prepare-pattern-draft returns nested shape:
                // { mode, pattern: { name, pattern_names: [...], components: [{name}] } }
                // prepare-pattern-change uses the same nested pattern object for replace/insert.
                $nested = is_array($output['pattern'] ?? null) ? $output['pattern'] : [];
                $candidates = [];

                foreach ([
                    $output['pattern_name'] ?? null,
                    $output['primary_pattern'] ?? null,
                    $nested['name'] ?? null,
                ] as $primary) {
                    if (is_string($primary) || is_scalar($primary)) {
                        $candidates[] = sanitize_text_field((string) $primary);
                    }
                }

                foreach ([$output['pattern_names'] ?? null, $nested['pattern_names'] ?? null] as $list) {
                    if (!is_array($list)) {
                        continue;
                    }

                    foreach ($list as $name) {
                        if (is_string($name) || is_scalar($name)) {
                            $candidates[] = sanitize_text_field((string) $name);
                        }
                    }
                }

                $components = $nested['components'] ?? $output['components'] ?? null;
                if (is_array($components)) {
                    foreach ($components as $component) {
                        if (!is_array($component)) {
                            continue;
                        }
                        $cname = $component['name'] ?? null;
                        if (is_string($cname) || is_scalar($cname)) {
                            $candidates[] = sanitize_text_field((string) $cname);
                        }
                    }
                }

                if (in_array($pattern_name, array_filter($candidates), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function has_any_structure(int $session_id): bool {
        if ($session_id <= 0) {
            return false;
        }

        return $this->patterns->session_has_pattern_structure($session_id)
            || $this->session_has_pattern_draft($session_id)
            || $this->session_has_custom_fallback($session_id);
    }

    public function session_has_pattern_draft(int $session_id): bool {
        foreach ($this->session_calls($session_id) as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            $mode = sanitize_key((string) ($output['mode'] ?? ''));

            if (
                'awpt/prepare-pattern-draft' === $tool
                && 'pattern' === $mode
            ) {
                return true;
            }

            if (
                'awpt/prepare-pattern-change' === $tool
                && in_array(
                    $mode,
                    [PatternPreparationReceipt::MODE_REPLACE, PatternPreparationReceipt::MODE_INSERT],
                    true,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function session_has_custom_fallback(int $session_id): bool {
        foreach ($this->session_calls($session_id) as $call) {
            if (
                in_array(
                    (string) ($call['tool'] ?? ''),
                    ['awpt/prepare-pattern-draft', 'awpt/prepare-pattern-change'],
                    true,
                )
                && 'success' === (string) ($call['status'] ?? '')
            ) {
                $output = is_array($call['output'] ?? null) ? $call['output'] : [];
                if ('custom_fallback' === sanitize_key((string) ($output['mode'] ?? ''))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reject dishonest no_recommendations when recommend-patterns returned items.
     *
     * @return \WP_Error|null
     */
    public function validate_unfit_code(int $session_id, string $unfit_code): ?\WP_Error {
        $unfit_code = sanitize_key($unfit_code);

        if ('' === $unfit_code) {
            return null;
        }

        $allowed = [
            PatternFirstPolicy::CODE_NO_RECOMMENDATIONS,
            PatternFirstPolicy::CODE_EXPLICIT_BESPOKE,
            PatternFirstPolicy::CODE_PRESERVATION_CONFLICT,
            PatternFirstPolicy::CODE_MEDIA_UNAVAILABLE,
            PatternFirstPolicy::CODE_SCOPE_MISMATCH,
        ];

        if (!in_array($unfit_code, $allowed, true)) {
            return new \WP_Error(
                'awpt_pattern_unfit_code_invalid',
                __('Unknown pattern_unfit_code.', 'agent-wordpress-terminal'),
                ['status' => 400, 'pattern_unfit_code' => $unfit_code],
            );
        }

        if (PatternFirstPolicy::CODE_NO_RECOMMENDATIONS !== $unfit_code) {
            // Other codes remain advisory telemetry (pre-M2 posture).
            return null;
        }

        $recommendations = $this->patterns->nonempty_recommendations($session_id);

        if ([] !== $recommendations) {
            return new \WP_Error(
                'awpt_pattern_unfit_dishonest',
                __(
                    'pattern_unfit_code "no_recommendations" is invalid because this session already has non-empty pattern recommendations. Read a recommended pattern, use propose-pattern-insert, or choose a different unfit code (e.g. scope_mismatch, explicit_bespoke).',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'recommended_next_tools' => [
                        ['tool' => 'awpt/read-pattern', 'reason' => 'Load structure for a recommended pattern before adapting.'],
                        ['tool' => 'awpt/propose-pattern-insert', 'reason' => 'Prefer inserting a recommended theme pattern when it fits.'],
                    ],
                ],
            );
        }

        return null;
    }

    /**
     * Require structure evidence when claiming a pattern_name on a full rewrite.
     *
     * @return \WP_Error|null
     */
    public function require_read_for_pattern_name(int $session_id, string $pattern_name): ?\WP_Error {
        $pattern_name = sanitize_text_field($pattern_name);

        if ('' === $pattern_name) {
            return null;
        }

        if ($this->has_read($session_id, $pattern_name)) {
            return null;
        }

        return new \WP_Error(
            'awpt_pattern_not_read',
            __(
                'Read the selected pattern (awpt/read-pattern) or prepare a pattern draft before using it as the basis for a layout rewrite.',
                'agent-wordpress-terminal',
            ),
            [
                'status' => 400,
                'pattern_name' => $pattern_name,
                'recommended_next_tools' => [
                    ['tool' => 'awpt/read-pattern', 'input' => ['name' => $pattern_name]],
                    ['tool' => 'awpt/prepare-pattern-draft', 'reason' => 'Or prepare an ordered pattern composition in one call.'],
                ],
            ],
        );
    }

    /** Clear mid-turn evidence for tests. */
    public static function reset_turn_evidence(?int $session_id = null): void {
        TurnToolEvidence::reset($session_id);
    }
}
