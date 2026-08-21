<?php

/**
 * Durable state for the two-turn Improve workflow.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\DesignSystemContextService;
use AWPT\Support\ImprovePagePrompt;
use AWPT\Support\Json;

if (!defined('ABSPATH')) {
    exit();
}

/** Stores and validates one active Improve workflow per session. */
final class ImproveWorkflowRepository {
    public const TTL_SECONDS = 3600;

    /** @var array<int, array<string, mixed>> Request-local cache. */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public function begin_evaluate(int $session_id, int $focus_post_id, string $turn_id): array {
        $now = time();
        $design = new DesignSystemContextService()->snapshot('evaluate');
        $workflow = [
            'id' => (string) wp_generate_uuid4(),
            'type' => 'improve',
            'version' => ImprovePagePrompt::PROMPT_VERSION_TWO_STEP,
            'state' => 'evaluating',
            'focus_post_id' => max(0, $focus_post_id),
            'evaluate_turn_id' => sanitize_key($turn_id),
            'act_turn_id' => '',
            'plan' => '',
            'units' => [],
            'cursor' => 0,
            'action_ids' => [],
            'error_code' => '',
            'error_message' => '',
            'design_context_hash' => (string) ($design['hash'] ?? ''),
            'design_catalog_hash' => (string) ($design['catalog_hash'] ?? ''),
            'pattern_catalog_hash' => (string) ($design['pattern_catalog_hash'] ?? ''),
            'design_identity' => $design['identity'] ?? [],
            'guidance_ids' => $design['sections']['constraints']['guidance_ids'] ?? [],
            'pattern_evidence' => [],
            'created_at' => gmdate('c', $now),
            'updated_at' => gmdate('c', $now),
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /** @return array<string, mixed>|null */
    public function get(int $session_id): ?array {
        if (array_key_exists($session_id, self::$cache)) {
            return self::$cache[$session_id];
        }
        $wpdb = WpDb::get();
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT improve_workflow_json FROM {$wpdb->prefix}awpt_sessions WHERE id = %d",
            $session_id,
        ));
        $workflow = Json::decode_array(is_string($value) ? $value : '');

        if ([] === $workflow) {
            return null;
        }
        self::$cache[$session_id] = $workflow;

        return $workflow;
    }

    /**
     * @param array<int, array<string, mixed>> $tool_calls Evaluate-turn tool results.
     * @return array<string, mixed>
     */
    public function plan_ready(int $session_id, string $plan, array $tool_calls = []): array {
        $workflow = $this->get($session_id) ?? [];
        if ('evaluating' !== (string) ($workflow['state'] ?? '') || '' === trim($plan)) {
            return $this->fail(
                $session_id,
                'awpt_improve_plan_missing',
                __('The evaluation did not produce an executable plan.', 'agent-wordpress-terminal'),
            );
        }

        $units = ImprovePagePrompt::units_from_plan($plan);

        if ([] === $units) {
            return $this->fail(
                $session_id,
                'awpt_improve_plan_missing',
                __('The evaluation did not produce an executable unit.', 'agent-wordpress-terminal'),
            );
        }

        // `none` is an evaluation conclusion, not an executable act. Keep it
        // in the narrative plan but never enqueue it behind real work.
        $units = array_values(array_filter(
            $units,
            static fn(array $unit): bool => 'none' !== (string) ($unit['op'] ?? ''),
        ));

        if ([] === $units) {
            if (!$this->has_verified_structure($tool_calls)) {
                return $this->fail(
                    $session_id,
                    'awpt_improve_structure_evidence_missing',
                    __(
                        'The evaluation did not verify the current block tree, so no action can be executed.',
                        'agent-wordpress-terminal',
                    ),
                );
            }

            $workflow['state'] = 'no_change';
            $workflow['plan'] = trim($plan);
            $workflow['units'] = [];
            $workflow['cursor'] = 0;
            $workflow['updated_at'] = gmdate('c');
            $this->save($session_id, $workflow);

            return $workflow;
        }

        $tree_snapshot = ImprovePagePrompt::tree_snapshot_from_tool_calls($tool_calls);
        $section_count = (int) $tree_snapshot['top_level_section_count'];
        $structure_nits = ImprovePagePrompt::plan_structure_nits($plan, $units, $section_count);
        $incomplete = ImprovePagePrompt::incomplete_units($units);

        if ([] !== $incomplete || [] !== $structure_nits) {
            return $this->fail(
                $session_id,
                'awpt_improve_units_incomplete',
                __(
                    'Evaluation units are still incomplete after repair: batch needs a brief or title; '
                    . 'pattern_replace/pattern_insert need paths, a brief or title, and pattern_name; '
                    . 'full-page replacements use the document path; deferred or content-incomplete units are rejected.',
                    'agent-wordpress-terminal',
                ),
            );
        }

        if (!$this->has_verified_structure($tool_calls)) {
            return $this->fail(
                $session_id,
                'awpt_improve_structure_evidence_missing',
                __(
                    'The evaluation did not verify the current block tree, so no action can be executed.',
                    'agent-wordpress-terminal',
                ),
            );
        }

        $pattern_evidence = $this->pattern_evidence_from_tool_calls($tool_calls);
        $evidence_names = [];
        foreach ($pattern_evidence as $entry) {
            $evidence_name = sanitize_text_field((string) ($entry['name'] ?? ''));
            if ('' !== $evidence_name) {
                $evidence_names[$evidence_name] = true;
            }
        }
        $missing_pattern_evidence = [];

        foreach ($units as $unit) {
            if (!in_array((string) ($unit['op'] ?? ''), ['pattern_replace', 'pattern_insert'], true)) {
                continue;
            }

            $pattern_name = sanitize_text_field((string) ($unit['pattern_name'] ?? ''));
            if ('' !== $pattern_name && !isset($evidence_names[$pattern_name])) {
                $missing_pattern_evidence[] = $pattern_name;
            }
        }

        if ([] !== $missing_pattern_evidence) {
            return $this->fail(
                $session_id,
                'awpt_improve_pattern_evidence_missing',
                sprintf(
                    /* translators: %s: comma-separated pattern names */
                    __(
                        'Pattern units named candidates that were not returned by recommend-patterns: %s.',
                        'agent-wordpress-terminal',
                    ),
                    implode(', ', array_unique($missing_pattern_evidence)),
                ),
            );
        }

        $workflow['state'] = 'plan_ready';
        $workflow['plan'] = trim($plan);
        $workflow['units'] = $units;
        $workflow['cursor'] = 0;
        $selected_names = array_fill_keys(
            array_values(array_filter(array_map(static fn(array $unit): string => sanitize_text_field(
                (string) ($unit['pattern_name'] ?? ''),
            ), $units))),
            true,
        );
        $selected = array_values(array_filter(
            $pattern_evidence,
            static fn(array $entry): bool => isset($selected_names[(string) ($entry['name'] ?? '')]),
        ));
        $alternatives = array_values(array_filter(
            $pattern_evidence,
            static fn(array $entry): bool => !isset($selected_names[(string) ($entry['name'] ?? '')]),
        ));
        $workflow['pattern_evidence'] = array_slice([...$selected, ...array_slice($alternatives, 0, 2)], 0, 5);
        $workflow['tree_snapshot'] = $tree_snapshot;
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /** @param array<int, array<string, mixed>> $tool_calls */
    private function has_verified_structure(array $tool_calls): bool {
        foreach ($tool_calls as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            if (in_array(
                (string) ($call['tool'] ?? ''),
                ['awpt/read-block-tree', 'wpab__awpt__read-block-tree'],
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Harvest the top ranked patterns from evaluate-turn recommend-patterns
     * results so act turns can resolve pattern names without re-ranking.
     *
     * @param array<int, array<string, mixed>> $tool_calls
     * @return list<array<string, mixed>>
     */
    public function pattern_evidence_from_tool_calls(array $tool_calls): array {
        /** @var list<array<string, mixed>> $evidence */
        $evidence = [];
        $seen = [];

        foreach ($tool_calls as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');

            if ('awpt/recommend-patterns' !== $tool && 'wpab__awpt__recommend-patterns' !== $tool) {
                continue;
            }

            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            $recommendations = is_array($output['recommendations'] ?? null) ? $output['recommendations'] : [];

            foreach ($recommendations as $recommendation) {
                if (!is_array($recommendation)) {
                    continue;
                }

                $pattern = is_array($recommendation['pattern'] ?? null) ? $recommendation['pattern'] : [];
                $name = sanitize_text_field((string) ($pattern['name'] ?? ''));

                if ('' === $name || isset($seen[$name]) || count($evidence) >= 5) {
                    continue;
                }

                $seen[$name] = true;
                $projected = new \AWPT\Support\PatternCandidateProjector()->one($recommendation);
                $evidence[] = \AWPT\Support\ArrayKey::string_map(array_intersect_key($projected, array_flip([
                    'name',
                    'title',
                    'role',
                    'summary',
                    'use_when',
                    'avoid_when',
                    'rationale',
                ])));
            }
        }

        return $evidence;
    }

    /** @return array<string, mixed>|\WP_Error */
    public function begin_act(
        int $session_id,
        string $workflow_id,
        int $focus_post_id,
        string $turn_id,
    ): array|\WP_Error {
        $workflow = $this->get($session_id);
        if (null === $workflow || !hash_equals((string) ($workflow['id'] ?? ''), $workflow_id)) {
            return new \WP_Error(
                'awpt_improve_workflow_not_found',
                __('The Improve plan could not be found. Evaluate the page again.', 'agent-wordpress-terminal'),
                ['status' => 404],
            );
        }
        if ((int) ($workflow['expires_at'] ?? 0) < time()) {
            $this->fail(
                $session_id,
                'awpt_improve_plan_expired',
                __('The Improve plan expired.', 'agent-wordpress-terminal'),
            );
            return new \WP_Error(
                'awpt_improve_plan_expired',
                __('The Improve plan expired. Evaluate the page again.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }
        if ('plan_ready' !== (string) ($workflow['state'] ?? '')) {
            return new \WP_Error(
                'awpt_improve_workflow_not_executable',
                __('This Improve plan has already been executed or is not ready.', 'agent-wordpress-terminal'),
                ['status' => 409, 'state' => (string) ($workflow['state'] ?? '')],
            );
        }
        $bound_focus = (int) ($workflow['focus_post_id'] ?? 0);
        if ($bound_focus > 0 && $focus_post_id > 0 && $bound_focus !== $focus_post_id) {
            return new \WP_Error(
                'awpt_improve_focus_mismatch',
                __(
                    'The focused page changed after evaluation. Evaluate the new page first.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409, 'expected_post_id' => $bound_focus, 'received_post_id' => $focus_post_id],
            );
        }

        $current_design = new DesignSystemContextService()->snapshot('evaluate');

        if (!hash_equals((string) ($workflow['design_context_hash'] ?? ''), (string) ($current_design['hash'] ?? ''))) {
            return new \WP_Error(
                'awpt_improve_design_context_changed',
                __(
                    'The active design system changed after evaluation. Evaluate the page again before staging changes.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409],
            );
        }

        $expected_pattern_catalog_hash = (string) ($workflow['pattern_catalog_hash'] ?? '');
        if (
            '' !== $expected_pattern_catalog_hash
            && !hash_equals($expected_pattern_catalog_hash, (string) ($current_design['pattern_catalog_hash'] ?? ''))
        ) {
            return new \WP_Error(
                'awpt_improve_pattern_catalog_changed',
                __(
                    'The active pattern guidance changed after evaluation. Evaluate the page again before staging changes.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409],
            );
        }

        $workflow['state'] = 'acting';
        $workflow['act_turn_id'] = sanitize_key($turn_id);
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /**
     * @param list<int> $action_ids
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    public function finish_act(int $session_id, array $action_ids, array $outcome): array {
        $workflow = $this->get($session_id) ?? [];
        $status = sanitize_key((string) ($outcome['status'] ?? ''));
        $prior_ids = is_array($workflow['action_ids'] ?? null) ? $workflow['action_ids'] : [];
        $workflow['action_ids'] = array_values(array_unique(array_filter(array_map('absint', [
            ...$prior_ids,
            ...$action_ids,
        ]))));

        if ([] !== $action_ids) {
            $workflow['cursor'] = max(0, (int) ($workflow['cursor'] ?? 0)) + 1;
        }

        $workflow['state'] = match (true) {
            [] !== $action_ids => 'staged',
            'failed' === $status => 'failed',
            default => 'no_change',
        };
        $workflow['error_code'] = 'failed' === $workflow['state']
            ? sanitize_key((string) ($outcome['error_code'] ?? 'awpt_improve_act_failed'))
            : '';
        $workflow['error_message'] = 'failed' === $workflow['state']
            ? sanitize_text_field((string) ($outcome['message'] ?? ''))
            : '';
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /** @return array<string, mixed> */
    public function fail(int $session_id, string $code, string $message): array {
        $workflow = $this->get($session_id) ?? [];
        $workflow['state'] = 'failed';
        $workflow['error_code'] = sanitize_key($code);
        $workflow['error_message'] = sanitize_text_field($message);
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    public function sync_action(int $session_id, int $action_id, string $status): void {
        $workflow = $this->get($session_id);
        if (null === $workflow) {
            return;
        }
        $action_ids = is_array($workflow['action_ids'] ?? null) ? $workflow['action_ids'] : [];
        if (!in_array($action_id, $action_ids, true)) {
            return;
        }
        $next = match ($status) {
            'applied' => 'applied',
            'rejected' => 'rejected',
            'rolled_back' => 'rolled_back',
            default => '',
        };
        if ('' === $next) {
            return;
        }

        if ('applied' === $next && self::has_remaining_units($workflow)) {
            $next = 'plan_ready';
        }

        $workflow['state'] = $next;
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);
    }

    /**
     * @param array<string, mixed> $workflow
     */
    public static function has_remaining_units(array $workflow): bool {
        $units = ImprovePagePrompt::normalize_units($workflow['units'] ?? null);

        return max(0, (int) ($workflow['cursor'] ?? 0)) < count($units);
    }

    /** @param array<string, mixed> $workflow */
    private function save(int $session_id, array $workflow): void {
        self::$cache[$session_id] = $workflow;
        $wpdb = WpDb::get();
        $wpdb->update(
            $wpdb->prefix . 'awpt_sessions',
            ['improve_workflow_json' => wp_json_encode($workflow)],
            ['id' => $session_id],
            ['%s'],
            ['%d'],
        );
    }
}
