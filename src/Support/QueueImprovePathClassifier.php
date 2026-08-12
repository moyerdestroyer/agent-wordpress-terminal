<?php

/**
 * Derive Improve-path labels from staged operations (not pattern_name alone).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Classifies queue Improve outcomes for audit summaries.
 *
 * Version this whenever classification rules change so historical summaries remain interpretable.
 */
final class QueueImprovePathClassifier {
    public const VERSION = '2';

    /**
     * Operations that stage server-expanded pattern markup (not freehand provenance).
     *
     * @var list<string>
     */
    public const SERVER_MATERIALIZED_OPERATIONS = [
        ActionOperations::PATTERN_INSERT,
        ActionOperations::PATTERN_REPLACE,
        ActionOperations::NEW_POST,
    ];

    /**
     * @param list<array<string, mixed>> $actions  Staged/applied action rows (payload keys optional).
     * @param list<string>               $tool_summary  "tool:status" strings.
     * @param list<string>               $recommended_patterns
     * @return array{
     *   path_used: string,
     *   server_materialized: bool,
     *   server_materialized_operations: list<string>,
     *   classifier_version: string
     * }
     */
    public function classify(array $actions, array $tool_summary = [], array $recommended_patterns = []): array {
        $ops = [];
        $has_pattern_name = false;
        $has_unfit = false;
        $materialized_ops = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $payload = is_array($action['payload'] ?? null)
                ? ArrayKey::as_map($action['payload'])
                : ArrayKey::string_map($action);
            $op = sanitize_key((string) ($payload['operation'] ?? $action['operation'] ?? ''));

            if ('' !== $op) {
                $ops[] = $op;
            }

            if ('' !== (string) ($payload['pattern_name'] ?? $action['pattern_name'] ?? '')) {
                $has_pattern_name = true;
            }

            if ('' !== (string) ($payload['pattern_unfit_code'] ?? $action['pattern_unfit_code'] ?? '')) {
                $has_unfit = true;
            }

            if ($this->is_server_materialized_action($payload, $op)) {
                $materialized_ops[] = $op;
            }
        }

        $materialized_ops = array_values(array_unique($materialized_ops));
        $server_materialized = [] !== $materialized_ops;
        $read_ok = $this->had_successful_structure_read($tool_summary);

        if (in_array(ActionOperations::PATTERN_REPLACE, $ops, true)) {
            $path = 'pattern_replace';
        } elseif (in_array(ActionOperations::PATTERN_INSERT, $ops, true)) {
            $path = 'pattern_insert';
        } elseif ($server_materialized && in_array(ActionOperations::NEW_POST, $ops, true)) {
            $path = 'patterned_new_post';
        } elseif ($has_pattern_name && $read_ok) {
            // Provenance-only freehand rewrite after a structure read.
            $path = 'pattern_provenance_freehand';
        } elseif ($has_pattern_name) {
            $path = 'adapted_freehand';
        } elseif ($has_unfit) {
            $path = 'surgical_with_unfit';
        } elseif ([] === $recommended_patterns && in_array('awpt/recommend-patterns:success', $tool_summary, true)) {
            $path = 'surgical_empty_recs';
        } elseif ([] !== $ops) {
            $path = 'surgical_or_other';
        } else {
            $path = 'no_change';
        }

        return [
            'path_used' => $path,
            'server_materialized' => $server_materialized,
            'server_materialized_operations' => $materialized_ops,
            'classifier_version' => self::VERSION,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function is_server_materialized_action(array $payload, string $operation = ''): bool {
        $op = '' !== $operation ? $operation : sanitize_key((string) ($payload['operation'] ?? ''));

        if (ActionOperations::PATTERN_INSERT === $op || ActionOperations::PATTERN_REPLACE === $op) {
            return true;
        }

        if (ActionOperations::NEW_POST !== $op) {
            return false;
        }

        $mode = sanitize_key((string) ($payload['pattern_mode'] ?? ''));

        if (in_array($mode, ['materialized', 'prepend'], true)) {
            return true;
        }

        $manifest = is_array($payload['composition_manifest'] ?? null) ? $payload['composition_manifest'] : [];
        $patterns = is_array($manifest['patterns'] ?? null) ? $manifest['patterns'] : [];

        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }

            $entry_mode = sanitize_key((string) ($pattern['mode'] ?? ''));

            if (in_array($entry_mode, ['materialized', 'inserted', 'replaced', 'prepend'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tool_summary */
    private function had_successful_structure_read(array $tool_summary): bool {
        foreach ($tool_summary as $entry) {
            $line = $entry;

            if (
                str_starts_with($line, 'awpt/read-pattern:success')
                || str_starts_with($line, 'awpt/prepare-pattern-draft:success')
                || str_starts_with($line, 'awpt/prepare-pattern-change:success')
            ) {
                return true;
            }
        }

        return false;
    }
}
