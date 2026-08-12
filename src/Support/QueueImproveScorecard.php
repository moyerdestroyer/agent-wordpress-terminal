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
    public const VERSION = '2';

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
     *   run_id: string,
     *   scenario_class: string,
     *   eligible_structural: bool,
     *   eligibility_reason: string,
     *   path_used: string,
     *   server_materialized: bool,
     *   prepare_change_success: int,
     *   propose_replace_success: int,
     *   propose_insert_success: int,
     *   prepare_change_attempted: int,
     *   freehand_provenance: bool,
     *   tool_calls: int,
     *   wall_s: float|null,
     *   first_proposal_valid: bool|null,
     *   turn_outcome_status: string,
     *   provider_turns: int|null,
     *   correction_count: int,
     *   funnel_stage: string
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
        $propose_insert_success = $this->count_tool_prefix($tools, 'awpt/propose-pattern-insert:success');
        $proposal_attempts =
            $this->count_tool_prefix($tools, 'awpt/propose-pattern-replace:')
            + $this->count_tool_prefix($tools, 'awpt/propose-pattern-insert:');
        $proposal_successes = $propose_replace_success + $propose_insert_success;
        $correction_count = max(0, (int) ($summary['correction_count'] ?? ($proposal_attempts - $proposal_successes)));

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
        $scenario_class = sanitize_key(
            (string) ($summary['scenario_class'] ?? $summary['meta']['scenario_class'] ?? ''),
        );

        return [
            'scorecard_version' => self::VERSION,
            'post_id' => $post_id,
            'run_id' => sanitize_text_field((string) ($summary['run_id'] ?? $summary['meta']['run_id'] ?? '')),
            'scenario_class' => $scenario_class,
            'eligible_structural' => $eligibility['eligible'],
            'eligibility_reason' => $eligibility['reason'],
            'path_used' => $path,
            'server_materialized' => $server_materialized,
            'prepare_change_success' => $prepare_success > 0 ? 1 : 0,
            'propose_replace_success' => $propose_replace_success > 0 ? 1 : 0,
            'propose_insert_success' => $propose_insert_success > 0 ? 1 : 0,
            'prepare_change_attempted' => $prepare_attempted > 0 ? 1 : 0,
            'freehand_provenance' => in_array($path, self::FREEHAND_PATHS, true),
            'tool_calls' => count($tools),
            'wall_s' => $wall,
            'first_proposal_valid' => $first_valid,
            'turn_outcome_status' => $outcome_status,
            'provider_turns' => $provider_turns,
            'correction_count' => $correction_count,
            'funnel_stage' => $this->funnel_stage($tools, [
                'prepare_attempted' => $prepare_attempted > 0,
                'prepare_success' => $prepare_success > 0,
                'proposal_success' => $propose_replace_success > 0 || $propose_insert_success > 0,
                'server_materialized' => $server_materialized,
                'correction_count' => $correction_count,
            ]),
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
        $insert_ok = 0;
        $scenario_counts = [];
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
            $scenario_class = (string) ($row['scenario_class'] ?? 'unclassified');
            $scenario_counts[$scenario_class] = ($scenario_counts[$scenario_class] ?? 0) + 1;

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
            if (!empty($row['propose_insert_success'])) {
                ++$insert_ok;
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
        $s_insert = 0;
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
            if (!empty($row['propose_insert_success'])) {
                ++$s_insert;
            }

            if (!empty($row['freehand_provenance'])) {
                ++$s_freehand;
            }
        }

        ksort($all_paths);
        ksort($s_paths);
        ksort($scenario_counts);

        return [
            'scorecard_version' => self::VERSION,
            'label' => $meta['label'] ?? '',
            'note' => $meta['note'] ?? '',
            'generated_at' => gmdate('c'),
            'n' => $n,
            'n_structural_eligible' => $sn,
            'n_error' => $errors,
            'path_counts' => $all_paths,
            'scenario_counts' => $scenario_counts,
            'rates_all' => [
                'server_materialized' => $this->rate($server_mat, $n),
                'prepare_change_success' => $this->rate($prepare_ok, $n),
                'prepare_change_attempted' => $this->rate($prepare_attempted, $n),
                'propose_replace_success' => $this->rate($replace_ok, $n),
                'propose_insert_success' => $this->rate($insert_ok, $n),
                'freehand_provenance' => $this->rate($freehand, $n),
                'first_proposal_valid' => $this->rate($first_valid, $first_valid_known),
            ],
            'counts_all' => [
                'server_materialized' => $server_mat,
                'prepare_change_success' => $prepare_ok,
                'prepare_change_attempted' => $prepare_attempted,
                'propose_replace_success' => $replace_ok,
                'propose_insert_success' => $insert_ok,
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
                    'propose_insert_success' => $this->rate($s_insert, $sn),
                    'freehand_provenance' => $this->rate($s_freehand, $sn),
                ],
                'counts' => [
                    'server_materialized' => $s_server,
                    'prepare_change_success' => $s_prepare,
                    'prepare_change_attempted' => $s_prepare_attempted,
                    'propose_replace_success' => $s_replace,
                    'propose_insert_success' => $s_insert,
                    'freehand_provenance' => $s_freehand,
                ],
            ],
            'wall_s_mean' => $wall_n > 0 ? round($wall_sum / $wall_n, 1) : null,
            'runs' => array_values(array_map(static fn(array $row): array => [
                'post_id' => (int) ($row['post_id'] ?? 0),
                'run_id' => (string) ($row['run_id'] ?? ''),
                'scenario_class' => (string) ($row['scenario_class'] ?? ''),
                'eligible_structural' => (bool) ($row['eligible_structural'] ?? false),
                'path_used' => (string) ($row['path_used'] ?? ''),
                'server_materialized' => (bool) ($row['server_materialized'] ?? false),
                'prepare_change_success' => (int) ($row['prepare_change_success'] ?? 0),
                'propose_replace_success' => (int) ($row['propose_replace_success'] ?? 0),
                'propose_insert_success' => (int) ($row['propose_insert_success'] ?? 0),
                'funnel_stage' => (string) ($row['funnel_stage'] ?? ''),
                'correction_count' => (int) ($row['correction_count'] ?? 0),
                'freehand_provenance' => (bool) ($row['freehand_provenance'] ?? false),
                'wall_s' => $row['wall_s'] ?? null,
                'first_proposal_valid' => $row['first_proposal_valid'] ?? null,
                'turn_outcome_status' => (string) ($row['turn_outcome_status'] ?? ''),
            ], array_values(array_filter($rows, 'is_array')))),
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
            $path = $path;

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

            $row = $this->from_run_summary(ArrayKey::string_map($decoded));

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
            $input = $input;

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

        $files = array_values(array_unique(array_filter($files, static function (string $file): bool {
            $base = basename($file);

            // Summaries only; v2 adds a unique run id after the post id.
            return (
                1 === preg_match('/^awpt-queue-\d+(?:-[a-z0-9-]+)?\.json$/', $base)
                && !str_ends_with($base, '.raw.json')
            );
        })));

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
        $scenario_class = sanitize_key((string) ($summary['scenario_class'] ?? $meta['scenario_class'] ?? ''));
        if (in_array($scenario_class, ['structural_replace', 'additive_insert'], true)) {
            return ['eligible' => true, 'reason' => 'declared_' . $scenario_class];
        }
        if (in_array($scenario_class, ['surgical_copy', 'no_change'], true)) {
            return ['eligible' => false, 'reason' => 'declared_' . $scenario_class];
        }
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
                !(
                    str_starts_with($line, 'awpt/read-block-tree:')
                    || str_starts_with($line, 'awpt/analyze-page:')
                    || str_starts_with($line, 'awpt/prepare-pattern-change:')
                )
            ) {
                continue;
            }

            ++$structure_reads;
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
            if (!str_starts_with($line, $prefix)) {
                continue;
            }

            ++$n;
        }

        return $n;
    }

    /**
     * @param list<string> $tools
     * @param array{
     *   prepare_attempted: bool,
     *   prepare_success: bool,
     *   proposal_success: bool,
     *   server_materialized: bool,
     *   correction_count: int
     * } $facts
     */
    private function funnel_stage(array $tools, array $facts): string {
        if ($facts['server_materialized'] && $facts['correction_count'] > 0) {
            return 'prepared_then_corrected';
        }
        if ($facts['server_materialized']) {
            return 'server_materialized';
        }
        if ($facts['proposal_success']) {
            return 'proposal_succeeded_not_materialized';
        }
        $proposal_attempted =
            $this->count_tool_prefix($tools, 'awpt/propose-pattern-replace:') > 0
            || $this->count_tool_prefix($tools, 'awpt/propose-pattern-insert:') > 0;
        if ($proposal_attempted) {
            return 'proposal_failed';
        }
        if ($facts['prepare_success']) {
            return 'prepared_abandoned';
        }
        if ($facts['prepare_attempted']) {
            return 'preparation_failed_or_fallback';
        }

        return 'preparation_not_attempted';
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
