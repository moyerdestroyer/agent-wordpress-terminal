<?php

/**
 * Shapes failed tool outputs for the next provider round (CLI-style stderr).
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
 * Promote recovery fields out of nested error_data so the model can retry
 * like a terminal agent reading a failed CLI command — error, fix, retry_with.
 */
final class FailedToolFeedback {
    private const MAX_SLOTS = 12;
    private const MAX_FINDINGS = 6;

    /**
     * Provider-facing payload for a failed or rejected tool call.
     *
     * @param array<string, mixed> $output Failed tool output (error, error_code, error_data…).
     * @return array<string, mixed>
     */
    public static function for_provider(string $tool, array $output): array {
        $error_data = ArrayKey::as_map($output['error_data'] ?? null);
        $constraints = is_array($error_data['constraints'] ?? null) ? array_values($error_data['constraints']) : [];
        $feedback = ArrayKey::as_map($error_data['agent_feedback'] ?? null);
        $error_code = sanitize_key((string) ($output['error_code'] ?? ''));

        $payload = [
            'ok' => false,
            'tool' => $tool,
            'error' => sanitize_textarea_field((string) ($output['error'] ?? 'Tool failed.')),
            'error_code' => $error_code,
            'instruction' => __(
                'This tool call failed. Change arguments using fix / retry_with / use above, then call the same tool again. Do not repeat the identical input.',
                'agent-wordpress-terminal',
            ),
        ];

        return self::with_optional_fields($payload, $error_data, $constraints, $feedback, $error_code);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $error_data
     * @param list<mixed>          $constraints
     * @param array<string, mixed> $feedback
     * @return array<string, mixed>
     */
    private static function with_optional_fields(
        array $payload,
        array $error_data,
        array $constraints,
        array $feedback,
        string $error_code,
    ): array {
        $fix = self::fix_text($error_data, $constraints, $feedback);

        if ('' !== $fix) {
            $payload['fix'] = $fix;
        }

        $retry = $error_data['retry_example'] ?? null;

        if (is_array($retry) && [] !== $retry) {
            $payload['retry_with'] = $retry;
        }

        $use = self::use_bundle($error_data);

        if ([] !== $use) {
            $payload['use'] = $use;
        }

        $next = $error_data['recommended_next_tools'] ?? null;

        if (is_array($next) && [] !== $next) {
            $payload['next_tools'] = array_values(array_slice($next, 0, 4));
        }

        $shaped = self::shape_constraints($constraints);

        if ([] !== $shaped) {
            $payload['constraints'] = $shaped;
        }

        $do_not = self::do_not_lines($error_data, $error_code);

        if ([] !== $do_not) {
            $payload['do_not'] = $do_not;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $error_data
     * @param list<mixed>          $constraints
     * @param array<string, mixed> $feedback
     */
    private static function fix_text(array $error_data, array $constraints, array $feedback): string {
        $recovery = trim((string) ($error_data['recovery'] ?? ''));

        if ('' !== $recovery) {
            return $recovery;
        }

        $summary = trim((string) ($feedback['summary'] ?? ''));

        if ('' !== $summary) {
            return $summary;
        }

        return self::hints_from_constraints($constraints);
    }

    /**
     * @param list<mixed> $constraints
     */
    private static function hints_from_constraints(array $constraints): string {
        $hints = [];

        foreach ($constraints as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (self::string_list($row['hints'] ?? null) as $hint) {
                $hints[] = $hint;
            }

            $summary = trim((string) ($row['summary'] ?? ''));

            if ('' !== $summary && [] === $hints) {
                $hints[] = $summary;
            }
        }

        return [] !== $hints ? implode(' ', array_slice($hints, 0, 3)) : '';
    }

    /**
     * @param list<mixed> $constraints
     * @return list<array<string, mixed>>
     */
    private static function shape_constraints(array $constraints): array {
        $out = [];

        foreach ($constraints as $row) {
            if (!is_array($row)) {
                $out[] = ['summary' => (string) $row];
                continue;
            }

            $item = [
                'id' => sanitize_key((string) ($row['id'] ?? '')),
                'error_code' => sanitize_key((string) ($row['error_code'] ?? '')),
                'summary' => sanitize_textarea_field((string) ($row['summary'] ?? '')),
            ];
            $hints = self::string_list($row['hints'] ?? null);

            if ([] !== $hints) {
                $item['hints'] = $hints;
            }

            $facts = ArrayKey::as_map($row['facts'] ?? null);

            if ([] !== $facts) {
                $item['facts'] = self::compact_facts($facts);
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array<string, mixed>
     */
    private static function use_bundle(array $error_data): array {
        $use = self::scalar_keys($error_data, [
            'preparation_id',
            'block_path',
            'current_fingerprint',
            'expected_fingerprint',
            'received_fingerprint',
            'pattern_name',
        ]);

        $slots = is_array($error_data['editable_slots'] ?? null) ? $error_data['editable_slots'] : [];

        if ([] !== $slots) {
            $use['editable_slots'] = self::compact_slots(array_values($slots));
        }

        $media = is_array($error_data['media_slots'] ?? null) ? $error_data['media_slots'] : [];

        if ([] !== $media) {
            $use['media_slots'] = self::compact_slots(array_values($media));
        }

        $carry = $error_data['carry_forward'] ?? null;

        if (is_array($carry) && [] !== $carry) {
            $use['carry_forward'] = self::compact_facts(ArrayKey::string_map($carry));
        }

        $findings = self::finding_rows($error_data);

        if ([] !== $findings) {
            $use['findings'] = $findings;
        }

        return $use;
    }

    /**
     * @param array<string, mixed> $error_data
     * @param list<string>         $keys
     * @return array<string, mixed>
     */
    private static function scalar_keys(array $error_data, array $keys): array {
        $use = [];

        foreach ($keys as $key) {
            $value = $error_data[$key] ?? null;

            if (is_string($value) && '' !== trim($value)) {
                $use[$key] = sanitize_text_field($value);
            } elseif (is_int($value) || is_float($value)) {
                $use[$key] = $value;
            }
        }

        return $use;
    }

    /**
     * @param array<string, mixed> $error_data
     * @return list<array<string, mixed>>
     */
    private static function finding_rows(array $error_data): array {
        $raw = $error_data['blocking_findings'] ?? null;

        if (!is_array($raw) || [] === $raw) {
            $raw = $error_data['validation_findings'] ?? null;
        }

        if (!is_array($raw) || [] === $raw) {
            return [];
        }

        $out = [];

        foreach (array_slice($raw, 0, self::MAX_FINDINGS) as $row) {
            if (!is_array($row)) {
                $out[] = ['message' => (string) $row];
                continue;
            }

            $item = array_filter(
                [
                    'code' => sanitize_key((string) ($row['code'] ?? $row['id'] ?? '')),
                    'severity' => sanitize_key((string) ($row['severity'] ?? '')),
                    'message' => sanitize_textarea_field((string) ($row['message'] ?? $row['summary'] ?? '')),
                    'path' => sanitize_text_field((string) ($row['path'] ?? $row['block_path'] ?? '')),
                ],
                static fn(mixed $v): bool => '' !== $v,
            );

            if ([] !== $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $slots
     * @return list<array<string, mixed>>
     */
    private static function compact_slots(array $slots): array {
        $out = [];

        foreach (array_slice($slots, 0, self::MAX_SLOTS) as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $row = array_filter(
                [
                    'block_path' => sanitize_text_field((string) ($slot['block_path'] ?? $slot['path'] ?? '')),
                    'slot_id' => sanitize_key((string) ($slot['slot_id'] ?? '')),
                    'role' => sanitize_key((string) ($slot['role'] ?? '')),
                    'name' => sanitize_text_field((string) ($slot['name'] ?? $slot['block_name'] ?? '')),
                    'excerpt' => sanitize_textarea_field((string) ($slot['excerpt'] ?? $slot['text'] ?? '')),
                ],
                static fn(mixed $v): bool => '' !== $v,
            );

            if ([] !== $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>
     */
    private static function compact_facts(array $facts): array {
        $out = [];

        foreach ($facts as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $out[$key] = is_string($value) ? sanitize_textarea_field($value) : $value;
                continue;
            }

            if (!is_array($value) || !self::is_list_of_scalars($value)) {
                continue;
            }

            $scalars = [];

            foreach (array_slice($value, 0, 12) as $item) {
                if (is_string($item)) {
                    $scalars[] = sanitize_text_field($item);
                } elseif (is_int($item) || is_float($item) || is_bool($item)) {
                    $scalars[] = $item;
                }
            }

            $out[$key] = $scalars;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function is_list_of_scalars(array $value): bool {
        if (!array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item) && !is_float($item) && !is_bool($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function string_list(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $trimmed = trim($item);

            if ('' !== $trimmed) {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $error_data
     * @return list<string>
     */
    private static function do_not_lines(array $error_data, string $error_code): array {
        $lines = [];
        $has_prep = is_string($error_data['preparation_id'] ?? null) && '' !== trim($error_data['preparation_id']);
        $has_slots = is_array($error_data['editable_slots'] ?? null) && [] !== $error_data['editable_slots'];

        if ($has_prep || $has_slots) {
            $lines[] = __(
                'Do not invent preparation_id or block_path values; copy them from use.* above.',
                'agent-wordpress-terminal',
            );
            $lines[] = __(
                'Do not call prepare-pattern-change again for the same section unless preparation_id is missing.',
                'agent-wordpress-terminal',
            );
        }

        if (in_array($error_code, ['awpt_block_fingerprint_mismatch', 'ability_invalid_input'], true)) {
            $lines[] = __(
                'Do not reuse a shortened or stale fingerprint; copy current_fingerprint from use or the latest read.',
                'agent-wordpress-terminal',
            );
        }

        if ('awpt_pattern_not_read' === $error_code) {
            $lines[] = __(
                'Do not retry the proposal until awpt/read-pattern succeeds for the named pattern.',
                'agent-wordpress-terminal',
            );
        }

        return array_values(array_unique($lines));
    }
}
