<?php

/**
 * Improve cohort scorecard: per-run metrics + aggregate rates (M5).
 *
 * Report-only — no invented percentage targets.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds structural-eligibility-aware scorecards from queue Improve summaries.
 */
final class QueueImproveScorecard {
    public const VERSION = '1';

    /** Paths that count as freehand provenance (not server-materialized pattern ops). */
    private const FREEHAND_PATHS = [
        'pattern_provenance_freehand',
        'adapted_freehand',
    ];

    /**
     * Score one queue-improve summary (or raw-compatible map).
     *
     * @param array<string, mixed> $summary
     * @return array{
     *   scorecard_version: string,
     *   post_id: int,
     *   eligible_structural: bool,
     *   eligibility_reason: string,
     *   path_used: string,
     *   server_materialized: bool,
     *   prepare_change_success: int,
     *   propose_replace_success: int,
     *   prepare_change_attempted: int,
     *   freehand_provenance: bool,
     *   tool_calls: int,
     *   wall_s: float|null,
     *   first_proposal_valid: bool|null,
     *   turn_outcome_status: string,
     *   provider_turns: int|null
     * }
     */
    public function from_run_summary(array $summary): array {
        $tools = $this->tool_lines($summary);
        $path = sanitize_key((string) ($summary['path_used'] ?? ''));
        $server_materialized = (bool) ($summary['server_materialized'] ?? false);
        $post_id = (int) ($summary['post_id'] ?? 0);

        $prepare_attempted = $this->count_tool_prefix($tools, 'awpt/prepare-pattern-change:');
        $prepare_success = $this->count_tool_prefix($tools, 'awpt/prepare-pattern-change:success');
        $propose_replace_success = $this->count_tool_prefix($tools, 'awpt/propose-pattern-replace:success');

        // Path/ops can succeed even if tool summary naming differs slightly.
        if (
            0 === $propose_replace_success
            && (
                'pattern_replace' === $path
                || $this->actions_include_operation($summary, ActionOperations::PATTERN_REPLACE)
            )
        ) {
            $propose_replace_success = 1;
        }

        $eligibility = $this->eligibility($summary, $tools);

        $first_valid = $summary['first_proposal_valid'] ?? null;
        if (null !== $first_valid) {
            $first_valid = (bool) $first_valid;
        }

        $wall = $summary['elapsed_s'] ?? $summary['wall_s'] ?? null;
        $wall = is_numeric($wall) ? (float) $wall : null;

        $turn_outcome = is_array($summary['turn_outcome'] ?? null) ? $summary['turn_outcome'] : [];
        $outcome_status = sanitize_key((string) ($turn_outcome['status'] ?? ''));

        $provider_turns = $summary['provider_turns'] ?? $summary['meta']['provider_turns'] ?? null;
        $provider_turns = is_numeric($provider_turns) ? (int) $provider_turns : null;

        return [
            'scorecard_version' => self::VERSION,
            'post_id' => $post_id,
            'eligible_structural' => $eligibility['eligible'],
            'eligibility_reason' => $eligibility['reason'],
            'path_used' => $path,
            'server_materialized' => $server_materialized,
            'prepare_change_success' => $prepare_success > 0 ? 1 : 0,
            'propose_replace_success' => $propose_replace_success > 0 ? 1 : 0,
            'prepare_change_attempted' => $prepare_attempted > 0 ? 1 : 0,
            'freehand_provenance' => in_array($path, self::FREEHAND_PATHS, true),
            'tool_calls' => count($tools),
            'wall_s' => $wall,
            'first_proposal_valid' => $first_valid,
            'turn_outcome_status' => $outcome_status,
            'provider_turns' => $provider_turns,
        ];
    }

    /**
     * Aggregate per-run scorecards into cohort rates (denominators explicit).
     *
     * @param list<array<string, mixed>> $rows  Output of from_run_summary (or compatible).
     * @param array{label?: string, note?: string} $meta
     * @return array<string, mixed>
     */
    public function aggregate(array $rows, array $meta = []): array {
        $n = count($rows);
        $structural = [];
        $all_paths = [];
        $server_mat = 0;
        $prepare_ok = 0;
        $prepare_attempted = 0;
        $replace_ok = 0;
        $freehand = 0;
        $first_valid = 0;
        $first_valid_known = 0;
        $wall_sum = 0.0;
        $wall_n = 0;
        $errors = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = (string) ($row['path_used'] ?? '');
            $all_paths[$path] = ($all_paths[$path] ?? 0) + 1;

            if (!empty($row['server_materialized'])) {
                ++$server_mat;
            }

            if (!empty($row['prepare_change_success'])) {
                ++$prepare_ok;
            }

            if (!empty($row['prepare_change_attempted'])) {
                ++$prepare_attempted;
            }

            if (!empty($row['propose_replace_success'])) {
                ++$replace_ok;
            }

            if (!empty($row['freehand_provenance'])) {
                ++$freehand;
            }

            if (array_key_exists('first_proposal_valid', $row) && null !== $row['first_proposal_valid']) {
                ++$first_valid_known;
                if ($row['first_proposal_valid']) {
                    ++$first_valid;
                }
            }

            if (is_numeric($row['wall_s'] ?? null)) {
                $wall_sum += (float) $row['wall_s'];
                ++$wall_n;
            }

            if ('' !== (string) ($row['error_code'] ?? '')) {
                ++$errors;
            }

            if (!empty($row['eligible_structural'])) {
                $structural[] = $row;
            }
        }

        $sn = count($structural);
        $s_paths = [];
        $s_server = 0;
        $s_prepare = 0;
        $s_prepare_attempted = 0;
        $s_replace = 0;
        $s_freehand = 0;

        foreach ($structural as $row) {
            $path = (string) ($row['path_used'] ?? '');
            $s_paths[$path] = ($s_paths[$path] ?? 0) + 1;

            if (!empty($row['server_materialized'])) {
                ++$s_server;
            }

            if (!empty($row['prepare_change_success'])) {
                ++$s_prepare;
            }

            if (!empty($row['prepare_change_attempted'])) {
                ++$s_prepare_attempted;
            }

            if (!empty($row['propose_replace_success'])) {
                ++$s_replace;
            }

            if (!empty($row['freehand_provenance'])) {
                ++$s_freehand;
            }
        }

        ksort($all_paths);
        ksort($s_paths);

        return [
            'scorecard_version' => self::VERSION,
            'label' => (string) ($meta['label'] ?? ''),
            'note' => (string) ($meta['note'] ?? ''),
            'generated_at' => gmdate('c'),
            'n' => $n,
            'n_structural_eligible' => $sn,
            'n_error' => $errors,
            'path_counts' => $all_paths,
            'rates_all' => [
                'server_materialized' => $this->rate($server_mat, $n),
                'prepare_change_success' => $this->rate($prepare_ok, $n),
                'prepare_change_attempted' => $this->rate($prepare_attempted, $n),
                'propose_replace_success' => $this->rate($replace_ok, $n),
                'freehand_provenance' => $this->rate($freehand, $n),
                'first_proposal_valid' => $this->rate($first_valid, $first_valid_known),
            ],
            'counts_all' => [
                'server_materialized' => $server_mat,
                'prepare_change_success' => $prepare_ok,
                'prepare_change_attempted' => $prepare_attempted,
                'propose_replace_success' => $replace_ok,
                'freehand_provenance' => $freehand,
                'first_proposal_valid' => $first_valid,
                'first_proposal_valid_known' => $first_valid_known,
            ],
            'structural' => [
                'n' => $sn,
                'path_counts' => $s_paths,
                'rates' => [
                    'server_materialized' => $this->rate($s_server, $sn),
                    'prepare_change_success' => $this->rate($s_prepare, $sn),
                    'prepare_change_attempted' => $this->rate($s_prepare_attempted, $sn),
                    'propose_replace_success' => $this->rate($s_replace, $sn),
                    'freehand_provenance' => $this->rate($s_freehand, $sn),
                ],
                'counts' => [
                    'server_materialized' => $s_server,
                    'prepare_change_success' => $s_prepare,
                    'prepare_change_attempted' => $s_prepare_attempted,
                    'propose_replace_success' => $s_replace,
                    'freehand_provenance' => $s_freehand,
                ],
            ],
            'wall_s_mean' => $wall_n > 0 ? round($wall_sum / $wall_n, 1) : null,
            'runs' => array_values(array_map(
                static function (array $row): array {
                    return [
                        'post_id' => (int) ($row['post_id'] ?? 0),
                        'eligible_structural' => (bool) ($row['eligible_structural'] ?? false),
                        'path_used' => (string) ($row['path_used'] ?? ''),
                        'server_materialized' => (bool) ($row['server_materialized'] ?? false),
                        'prepare_change_success' => (int) ($row['prepare_change_success'] ?? 0),
                        'propose_replace_success' => (int) ($row['propose_replace_success'] ?? 0),
                        'freehand_provenance' => (bool) ($row['freehand_provenance'] ?? false),
                        'wall_s' => $row['wall_s'] ?? null,
                        'first_proposal_valid' => $row['first_proposal_valid'] ?? null,
                        'turn_outcome_status' => (string) ($row['turn_outcome_status'] ?? ''),
                    ];
                },
                array_values(array_filter($rows, 'is_array')),
            )),
            'policy' => 'Rates are report-only. Do not invent fixed targets (e.g. 70%) without denominators and a clean post-M3 cohort.',
        ];
    }

    /**
     * Load JSON run files and aggregate.
     *
     * @param list<string> $paths
     * @param array{label?: string, note?: string} $meta
     * @return array<string, mixed>
     */
    public function from_files(array $paths, array $meta = []): array {
        $rows = [];

        foreach ($paths as $path) {
            $path = (string) $path;

            if (!is_readable($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (!is_array($decoded)) {
                continue;
            }

            // Skip raw traces mistaken for summaries.
            if (isset($decoded['tool_calls']) && !isset($decoded['path_used']) && !isset($decoded['tools'])) {
                continue;
            }

            $row = $this->from_run_summary($decoded);

            if (isset($decoded['error']) && is_array($decoded['error'])) {
                $row['error_code'] = (string) ($decoded['error']['code'] ?? 'error');
            }

            $rows[] = $row;
        }

        return $this->aggregate($rows, $meta);
    }

    /**
     * Expand globs / dirs into summary JSON paths (excludes .raw / pre-m2 / logs).
     *
     * @param list<string> $inputs
     * @return list<string>
     */
    public function resolve_input_paths(array $inputs): array {
        $files = [];

        foreach ($inputs as $input) {
            $input = (string) $input;

            if (is_dir($input)) {
                $globbed = glob(rtrim($input, '/') . '/awpt-queue-*.json') ?: [];
                foreach ($globbed as $file) {
                    $files[] = $file;
                }
                continue;
            }

            if (str_contains($input, '*') || str_contains($input, '?')) {
                $globbed = glob($input) ?: [];
                foreach ($globbed as $file) {
                    $files[] = $file;
                }
                continue;
            }

            if (is_file($input)) {
                $files[] = $input;
            }
        }

        $files = array_values(array_unique(array_filter(
            $files,
            static function (string $file): bool {
                $base = basename($file);

                // Summaries only: awpt-queue-{id}.json (not .raw.json / .pre-m2.json / cohort-*).
                return 1 === preg_match('/^awpt-queue-\d+\.json$/', $base);
            },
        )));

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<string>         $tools
     * @return array{eligible: bool, reason: string}
     */
    private function eligibility(array $summary, array $tools): array {
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $prompt = (string) ($meta['prompt_version'] ?? '');

        // Queue Improve harness always uses redesign brief → structural by default.
        if (str_starts_with($prompt, 'improve-page')) {
            return [
                'eligible' => true,
                'reason' => 'improve_page_prompt',
            ];
        }

        $section_count = $summary['top_level_section_count'] ?? $meta['top_level_section_count'] ?? null;

        if (is_numeric($section_count) && (int) $section_count >= 2) {
            return [
                'eligible' => true,
                'reason' => 'multi_section_post',
            ];
        }

        // Heuristic from tools: redesign-style structure reads.
        $structure_reads = 0;
        foreach ($tools as $line) {
            if (
                str_starts_with($line, 'awpt/read-block-tree:')
                || str_starts_with($line, 'awpt/analyze-page:')
                || str_starts_with($line, 'awpt/prepare-pattern-change:')
            ) {
                ++$structure_reads;
            }
        }

        if ($structure_reads > 0) {
            return [
                'eligible' => true,
                'reason' => 'structure_tools_used',
            ];
        }

        // Historical summaries without meta: treat as structural if a path was classified.
        if ('' !== (string) ($summary['path_used'] ?? '')) {
            return [
                'eligible' => true,
                'reason' => 'classified_improve_run',
            ];
        }

        return [
            'eligible' => false,
            'reason' => 'not_structural',
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return list<string>
     */
    private function tool_lines(array $summary): array {
        $tools = $summary['tools'] ?? $summary['tool_summary'] ?? [];

        if (!is_array($tools)) {
            return [];
        }

        $lines = [];

        foreach ($tools as $entry) {
            if (is_string($entry)) {
                $lines[] = $entry;
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $tool = (string) ($entry['tool'] ?? $entry['name'] ?? '');
            $status = (string) ($entry['status'] ?? '');

            if ('' !== $tool) {
                $lines[] = '' !== $status ? $tool . ':' . $status : $tool;
            }
        }

        return $lines;
    }

    /** @param list<string> $tools */
    private function count_tool_prefix(array $tools, string $prefix): int {
        $n = 0;

        foreach ($tools as $line) {
            if (str_starts_with((string) $line, $prefix)) {
                ++$n;
            }
        }

        return $n;
    }

    /** @param array<string, mixed> $summary */
    private function actions_include_operation(array $summary, string $operation): bool {
        $actions = is_array($summary['actions'] ?? null) ? $summary['actions'] : [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $payload = is_array($action['payload'] ?? null) ? $action['payload'] : $action;
            $op = sanitize_key((string) ($payload['operation'] ?? $action['operation'] ?? ''));

            if ($op === $operation) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{count: int, denominator: int, rate: float|null}
     */
    private function rate(int $count, int $denominator): array {
        return [
            'count' => $count,
            'denominator' => $denominator,
            'rate' => $denominator > 0 ? round($count / $denominator, 4) : null,
        ];
    }
}
