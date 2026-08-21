<?php

/**
 * Turn-scoped open proposal constraints and merged recovery guidance.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Accumulates proposal failures so recovery addresses every open requirement.
 */
final class ProposalConstraintSet {
    /** @var array<string, array<string, mixed>> */
    private array $constraints = [];

    /**
     * @param list<array<string, mixed>> $tool_calls
     */
    public function ingest(array $tool_calls): void {
        foreach ($tool_calls as $call) {
            if (!ToolRegistry::is_proposal_ability((string) ($call['tool'] ?? ''))) {
                continue;
            }

            if ('success' === (string) ($call['status'] ?? '')) {
                continue;
            }

            $output = ArrayKey::as_map($call['output'] ?? null);
            $error_data = ArrayKey::as_map($output['error_data'] ?? null);
            $normalized = is_array($error_data['constraints'] ?? null) && [] !== $error_data['constraints']
                ? ArrayKey::list_of_maps($error_data['constraints'])
                : ProposalFailureNormalizer::normalize(
                    (string) ($output['error_code'] ?? ''),
                    $error_data,
                    (string) ($output['error'] ?? ''),
                );

            foreach ($normalized as $constraint) {
                $this->merge_constraint($constraint);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array {
        return array_values($this->constraints);
    }

    public function has(string $id): bool {
        return array_key_exists($id, $this->constraints);
    }

    public function is_empty(): bool {
        return [] === $this->constraints;
    }

    public function should_refresh_guidance(string $latest_failure_code): bool {
        return in_array(
            sanitize_key($latest_failure_code),
            [
                'awpt_presentation_content_loss',
                'awpt_pattern_not_read',
                'awpt_pattern_not_found',
                'awpt_required_page_h1_missing',
                'awpt_heading_level_skipped',
                'awpt_duplicate_page_heading',
                'awpt_block_fingerprint_mismatch',
                'awpt_pattern_text_block_not_editable',
                'awpt_block_inner_html_not_editable',
                'awpt_empty_block_attrs',
                'awpt_unknown_block_attribute',
                'awpt_multiple_proposals',
                'awpt_pattern_fallback_reason_required',
                'ability_invalid_input',
            ],
            true,
        );
    }

    /**
     * Build one corrective system message covering every open constraint.
     *
     * @param array<string, mixed> $context
     */
    public function recovery_guidance(int $proposal_failures, int $max_failures = 3, array $context = []): string {
        unset($context);

        if ($this->is_empty()) {
            return __(
                'The staging attempt failed validation. Read the failed tool result (fix, retry_with, use, constraints), change only the invalid arguments, and call the same propose tool again. Do not repeat unchanged arguments or restart discovery.',
                'agent-wordpress-terminal',
            );
        }

        if ($this->has('pattern_read_required')) {
            return __(
                'The proposal named a pattern that has not been read. Do not retry the proposal yet. Call awpt/read-pattern now with the exact name in the failed tool result (use / next_tools); perform no unrelated discovery.',
                'agent-wordpress-terminal',
            );
        }

        $lines = [
            __(
                'The staging attempt failed validation. Open requirements from the failed tool result (address all of them together):',
                'agent-wordpress-terminal',
            ),
        ];

        foreach ($this->all() as $constraint) {
            $summary = trim((string) ($constraint['summary'] ?? ''));
            $code = sanitize_key((string) ($constraint['error_code'] ?? ''));
            if ('' === $summary) {
                continue;
            }
            $lines[] = '' !== $code ? sprintf('- [%s] %s', $code, $summary) : '- ' . $summary;

            $facts = ArrayKey::as_map($constraint['facts'] ?? null);
            $fingerprint = trim((string) ($facts['current_fingerprint'] ?? ''));
            $path = trim((string) ($facts['block_path'] ?? ''));
            if ('' !== $fingerprint) {
                $lines[] = '' !== $path
                    ? sprintf('  current_fingerprint for block_path %s: %s', $path, $fingerprint)
                    : '  current_fingerprint: ' . $fingerprint;
            }

            foreach ($this->fact_lines($facts) as $fact_line) {
                $lines[] = '  ' . $fact_line;
            }

            $hints = is_array($constraint['hints'] ?? null) ? $constraint['hints'] : [];

            foreach ($hints as $hint) {
                if (!is_string($hint) || '' === trim($hint)) {
                    continue;
                }

                $lines[] = '  hint: ' . trim($hint);
            }
        }

        $lines[] = '';
        $lines[] = __('Compatible next attempt:', 'agent-wordpress-terminal');
        foreach ($this->compatible_attempt_lines($proposal_failures, $max_failures) as $line) {
            $lines[] = '- ' . $line;
        }

        $lines[] = __(
            'Choose any proposal tool that can satisfy every open requirement in one atomic attempt. Do not perform unrelated discovery.',
            'agent-wordpress-terminal',
        );

        return implode("\n", $lines);
    }

    /**
     * Render compact, actionable facts for recovery (skip already-printed keys).
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private function fact_lines(array $facts): array {
        $lines = [];
        $skip = ['current_fingerprint', 'block_path', 'recommended_next_tools', 'constraints'];

        foreach ($facts as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            if (is_bool($value)) {
                $lines[] = sprintf('%s: %s', $key, $value ? 'true' : 'false');
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $lines[] = sprintf('%s: %s', $key, (string) $value);
                continue;
            }

            if (is_string($value) && '' !== trim($value)) {
                $lines[] = sprintf('%s: %s', $key, mb_substr(trim($value), 0, 240, 'UTF-8'));
                continue;
            }

            if (is_array($value) && [] !== $value) {
                $encoded = wp_json_encode($value);

                if (is_string($encoded) && '' !== $encoded) {
                    $lines[] = sprintf('%s: %s', $key, mb_substr($encoded, 0, 240, 'UTF-8'));
                }
            }
        }

        return array_slice($lines, 0, 8);
    }

    /** @return list<string> */
    private function compatible_attempt_lines(int $proposal_failures, int $max_failures): array {
        $lines = [];
        $escalated = $proposal_failures >= 2 || $proposal_failures >= $max_failures;
        $needs_h1 = $this->has('requires_page_h1');
        $preserve = $this->has('preserve_content');

        if ($this->has('pattern_name_exact')) {
            $lines[] = __(
                'Reuse an exact pattern_name from a successful awpt/read-pattern result already in this turn; never paraphrase a slug. If no read pattern fits, omit pattern_name and compose with registered blocks and tokens.',
                'agent-wordpress-terminal',
            );
        }

        if ($this->has('pattern_fallback_reason')) {
            $lines[] = __(
                'Prefer a theme/site pattern when one fits; otherwise stage a clear bespoke layout without ceremony.',
                'agent-wordpress-terminal',
            );
        }

        if ($this->has('exact_fingerprints')) {
            $lines[] = __(
                'Copy 64-character fingerprints exactly from the evidence pack content_reads block tree; do not shorten, invent, or zero-fill them.',
                'agent-wordpress-terminal',
            );
        }

        if ($needs_h1) {
            $lines[] = __(
                'Include exactly one content H1: promote an existing heading with update_attrs level 1, or insert one core/heading level 1 that uses the verified page title before the first visible content block.',
                'agent-wordpress-terminal',
            );
        }

        if ($this->has('heading_outline')) {
            $lines[] = __(
                'Fix heading outline skips/duplicates while preserving the same copy and other verified changes.',
                'agent-wordpress-terminal',
            );
        }

        if ($preserve && $escalated) {
            if ($needs_h1) {
                $lines[] = __(
                    'Prefer a conservation-oriented awpt/propose-block-batch-update: keep existing prose/links/numbers; attribute and structure fixes are preferred. An H1 promote/insert that reuses verified title text is allowed because that requirement is still open. Avoid rewrite-heavy content replacement.',
                    'agent-wordpress-terminal',
                );
            } else {
                $lines[] = __(
                    'Use awpt/propose-block-batch-update now for a conservation-oriented recovery. Submit update_attrs changes against verified existing blocks only. Do not use replace_text, remove, or insert; do not send content or inner_html; do not rewrite links, numbers, labels, or prose. Stage the smallest meaningful hierarchy, spacing, alignment, or block-style improvement supported by the rendered evidence. If no such attribute-only improvement is possible, stop without proposing a change.',
                    'agent-wordpress-terminal',
                );
            }
        } elseif ($preserve) {
            $lines[] = __(
                'You may retry a full-document layout adaptation when that remains the best coherent solution, but it must preserve every substantive sentence, working link, number, media item, and legal reference. Use awpt/propose-block-batch-update when verified block changes fully solve the observed presentation problems. Do not reduce the transformation scope merely to evade validation.',
                'agent-wordpress-terminal',
            );
        }

        if ($this->has('unresolved_local_media')) {
            $lines[] = __(
                'Remove unresolved same-site media URLs from the proposal (omit optional images). Do not re-run recommend-patterns or invent attachment IDs.',
                'agent-wordpress-terminal',
            );
        }

        if (
            $this->has('unresolved_local_media')
            || $this->has('preserve_content')
            || $this->has('exact_fingerprints')
            || $this->has('heading_outline')
            || $this->has('requires_page_h1')
        ) {
            $lines[] = __(
                'Reuse this turn’s awpt/recommend-patterns and awpt/read-pattern results; do not re-call them unless the page archetype or chosen pattern_name changes.',
                'agent-wordpress-terminal',
            );
        }

        if ([] === $lines) {
            $lines[] = __(
                'Address every open requirement using exact identifiers already verified in this turn.',
                'agent-wordpress-terminal',
            );
        }

        return $lines;
    }

    /** @param array<string, mixed> $constraint */
    private function merge_constraint(array $constraint): void {
        $id = sanitize_key((string) ($constraint['id'] ?? ''));

        if ('' === $id) {
            return;
        }

        $existing = $this->constraints[$id] ?? [];
        $facts = array_merge(
            is_array($existing['facts'] ?? null) ? $existing['facts'] : [],
            is_array($constraint['facts'] ?? null) ? $constraint['facts'] : [],
        );
        $hints = array_values(array_unique(array_filter(
            [
                ...(is_array($existing['hints'] ?? null) ? $existing['hints'] : []),
                ...(is_array($constraint['hints'] ?? null) ? $constraint['hints'] : []),
            ],
            static fn(mixed $hint): bool => is_string($hint) && '' !== trim($hint),
        )));

        $this->constraints[$id] = [
            'id' => $id,
            'error_code' => sanitize_key((string) ($constraint['error_code'] ?? $existing['error_code'] ?? '')),
            'summary' => trim((string) ($constraint['summary'] ?? $existing['summary'] ?? '')),
            'facts' => $facts,
            'hints' => $hints,
        ];
    }
}
