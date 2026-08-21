<?php

/**
 * Run one evaluation scenario as a terminal chat turn (AgentRuntime), not the review queue UI.
 *
 * WP-CLI eats unknown --flags on eval-file; prefer bare tokens (or pass after `--`):
 *
 *   wp eval-file bin/run-scenario.php list
 *   wp eval-file bin/run-scenario.php S1-middle-swap
 *   wp eval-file bin/run-scenario.php S1 post=848
 *   wp eval-file bin/run-scenario.php S5-copy-only no-apply
 *   wp eval-file bin/run-scenario.php -- S2 --post=864 --no-apply
 *
 * Scenarios: evaluation/scenarios.json
 * Output:    tmp-queue-runs/awpt-scenario-{id}-post-{post_id}.json (+ .raw.json)
 */

$raw_cli = array_merge($args ?? [], array_slice($GLOBALS['argv'] ?? [], 1));
$cli_args = [];
foreach ($raw_cli as $arg) {
    if (!is_string($arg) || '' === $arg) {
        continue;
    }
    if (str_ends_with($arg, 'run-scenario.php') || str_ends_with($arg, 'eval-file')) {
        continue;
    }
    if ('--' === $arg) {
        continue;
    }
    $cli_args[] = $arg;
}
$cli_args = array_values($cli_args);

$list_only = false;
$no_apply = false;
$reuse_session = false;
$post_override = 0;
$scenario_id = '';

foreach ($cli_args as $arg) {
    if (in_array($arg, ['list', '--list', '-l'], true)) {
        $list_only = true;
        continue;
    }
    if (in_array($arg, ['no-apply', '--no-apply'], true)) {
        $no_apply = true;
        continue;
    }
    if (in_array($arg, ['reuse-session', '--reuse-session'], true)) {
        $reuse_session = true;
        continue;
    }
    if (str_starts_with($arg, '--post=')) {
        $post_override = (int) substr($arg, 7);
        continue;
    }
    if (str_starts_with($arg, 'post=')) {
        $post_override = (int) substr($arg, 5);
        continue;
    }
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if ('' === $scenario_id) {
        $scenario_id = (string) $arg;
    }
}

$plugin_root = dirname(__DIR__);
$scenarios_path = $plugin_root . '/evaluation/scenarios.json';
if (!is_readable($scenarios_path)) {
    fwrite(STDERR, "Missing scenarios file: {$scenarios_path}\n");
    exit(1);
}

$catalog = json_decode((string) file_get_contents($scenarios_path), true);
if (!is_array($catalog) || !is_array($catalog['scenarios'] ?? null)) {
    fwrite(STDERR, "Invalid scenarios.json\n");
    exit(1);
}

$scenarios = $catalog['scenarios'];
$by_id = [];
foreach ($scenarios as $row) {
    if (is_array($row) && !empty($row['id'])) {
        $by_id[(string) $row['id']] = $row;
        // Allow short ids: S1 → first id starting with S1-
        $short = (string) preg_replace('/^([A-Za-z]+\d+).*/', '$1', (string) $row['id']);
        if ($short !== (string) $row['id'] && !isset($by_id[$short])) {
            $by_id[$short] = $row;
        }
    }
}

if ($list_only) {
    echo "Scenarios in evaluation/scenarios.json:\n";
    foreach ($scenarios as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo
            sprintf(
                "  %s  post=%s  family=%s  %s\n",
                (string) ($row['id'] ?? '?'),
                (string) ($row['post_id'] ?? '?'),
                (string) ($row['family'] ?? ''),
                (string) ($row['title'] ?? ''),
            )
        ;
    }
    echo "\nRun: wp eval-file bin/run-scenario.php <id> [post=N] [no-apply]\n";
    exit(0);
}

if ('' === $scenario_id || !isset($by_id[$scenario_id])) {
    fwrite(STDERR, "Usage: wp eval-file bin/run-scenario.php <scenario_id|list> [post=N] [no-apply] [reuse-session]\n");
    fwrite(STDERR, "Unknown or missing scenario_id. Try: wp eval-file bin/run-scenario.php list\n");
    exit(1);
}

$scenario = $by_id[$scenario_id];
$canonical_id = (string) ($scenario['id'] ?? $scenario_id);
$post_id = $post_override > 0 ? $post_override : (int) ($scenario['post_id'] ?? 0);

if ($post_id <= 0) {
    fwrite(STDERR, "Scenario {$canonical_id} has no post_id; pass --post=N\n");
    exit(1);
}

$post = get_post($post_id);
if (!$post) {
    fwrite(STDERR, "Post {$post_id} not found\n");
    exit(1);
}

wp_set_current_user(1);

// Resolve prompt: task-shaped scenarios and production Improve use evaluate → act.
$two_step = false;
$evaluation_context = '';
$prompt = '';
$prompt_version = '';
if (($scenario['prompt_source'] ?? '') === 'ImprovePageTwoStep') {
    $two_step = true;
    $prompt_version = AWPT\Support\ImprovePagePrompt::PROMPT_VERSION_TWO_STEP;
} elseif (($scenario['prompt_source'] ?? '') === 'ImprovePagePrompt') {
    $prompt = AWPT\Support\ImprovePagePrompt::text();
    $prompt_version = AWPT\Support\ImprovePagePrompt::PROMPT_VERSION_LEGACY;
} elseif (is_string($scenario['prompt'] ?? null) && '' !== trim((string) $scenario['prompt'])) {
    $prompt = (string) $scenario['prompt'];
    $evaluation_context = $prompt;
    $two_step = true;
    $prompt_version = 'scenario-eval-act:' . $canonical_id;
} else {
    fwrite(STDERR, "Scenario {$canonical_id} has empty prompt\n");
    exit(1);
}

$sessions = new AWPT\Database\SessionRepository();
$session_reused = false;
if ($reuse_session) {
    $existing = $sessions->find_by_focus($post_id);
    if ($existing) {
        $session_id = (int) $existing['id'];
        $session_reused = true;
    } else {
        $created = $sessions->create(sprintf('Scenario %s #%d', $canonical_id, $post_id), $post_id);
        $session_id = (int) ($created['id'] ?? 0);
    }
} else {
    $created = $sessions->create(sprintf('Scenario %s #%d', $canonical_id, $post_id), $post_id);
    $session_id = (int) ($created['id'] ?? 0);
}

if ($session_id <= 0) {
    fwrite(STDERR, "Failed to create session\n");
    exit(1);
}

$run_id = gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false, false));
$turn_id = sprintf('scenario-%s-%d-%s', preg_replace('/[^a-z0-9-]+/i', '-', $canonical_id), $post_id, $run_id);
$out_dir = WP_CONTENT_DIR . '/plugins/agent-wordpress-terminal/tmp-queue-runs';
if (!is_dir($out_dir) && !wp_mkdir_p($out_dir)) {
    $out_dir = '/tmp';
}
$slug = preg_replace('/[^a-z0-9-]+/i', '-', $canonical_id);
$summary_path = sprintf('%s/awpt-scenario-%s-post-%d-%s.json', $out_dir, $slug, $post_id, $run_id);
$raw_path = sprintf('%s/awpt-scenario-%s-post-%d-%s.raw.json', $out_dir, $slug, $post_id, $run_id);

$provider_settings = [];
try {
    $settings = get_option('awpt_settings', []);
    if (is_array($settings)) {
        $provider_settings = [
            'provider' => (string) ($settings['provider'] ?? $settings['ai_provider'] ?? ''),
            'model' => (string) ($settings['model'] ?? $settings['ai_model'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $provider_settings = [];
}
// Fall back to discrete options used on this site.
if ('' === ($provider_settings['provider'] ?? '')) {
    $provider_settings['provider'] = (string) get_option('awpt_provider', '');
}
if ('' === ($provider_settings['model'] ?? '')) {
    $provider_settings['model'] = (string) get_option('awpt_openrouter_model', '');
}

$sections_before = AWPT\Support\PageSectionModel::from_content((string) $post->post_content, [
    'title' => (string) $post->post_title,
    'post_type' => (string) $post->post_type,
]);
$content_hash_before = hash('sha256', (string) $post->post_content);

echo
    sprintf(
        "START scenario=%s post=%d title=%s session=%d reused=%s turn=%s mode=%s\n",
        $canonical_id,
        $post_id,
        $post->post_title,
        $session_id,
        $session_reused ? 'yes' : 'no',
        $turn_id,
        $two_step ? 'eval-act' : 'one-shot',
    )
;
echo 'prompt_version=' . $prompt_version . "\n";
echo 'soft_expected_path=' . (string) ($scenario['soft_expected_path'] ?? 'null') . "\n";

$started = microtime(true);
$runtime = new AWPT\Agent\AgentRuntime();
$plan_text = '';
$eval_tool_calls = [];
$result = null;

if ($two_step) {
    $eval_turn_id = $turn_id . '-eval';
    $act_turn_id = $turn_id . '-act';
    echo "PHASE evaluate turn={$eval_turn_id}\n";
    $workflow_run = new AWPT\Support\ImproveWorkflowRunner()->run(
        $session_id,
        $post_id,
        $turn_id,
        $runtime,
        ['evaluation_context' => $evaluation_context],
    );
    $eval_result = $workflow_run['evaluate'];
    if (is_wp_error($eval_result)) {
        $result = $eval_result;
        $turn_id = $eval_turn_id;
    } else {
        $plan_text = trim((string) $workflow_run['plan']);
        $eval_tool_calls = is_array($eval_result['tool_calls'] ?? null) ? $eval_result['tool_calls'] : [];
        $eval_actions = is_array($eval_result['actions'] ?? null) ? $eval_result['actions'] : [];
        $eval_propose = 0;
        foreach ($eval_tool_calls as $call) {
            $tool = (string) ($call['tool'] ?? $call['name'] ?? '');
            if (str_starts_with($tool, 'awpt/propose-')) {
                ++$eval_propose;
            }
        }
        echo
            'plan_chars='
                . strlen($plan_text)
                . ' eval_actions='
                . count($eval_actions)
                . " eval_propose={$eval_propose}\n"
        ;
        echo "PHASE act turn={$act_turn_id}\n";
        $result = $workflow_run['act'];
        $turn_id = $act_turn_id;
    }
} else {
    $result = $runtime->handle_message($session_id, $prompt, [
        'turn_id' => $turn_id,
        'focus_post_id' => $post_id,
    ]);
}
$elapsed = round(microtime(true) - $started, 1);

$run_meta =
    [
        'run_id' => $run_id,
        'plugin_version' => defined('AWPT_VERSION') ? AWPT_VERSION : '',
        'prompt_version' => $prompt_version,
        'two_step' => $two_step,
        'plan_chars' => strlen($plan_text),
        'scenario_id' => $canonical_id,
        'scenario_family' => (string) ($scenario['family'] ?? ''),
        'scenario_class' => match ((string) ($scenario['family'] ?? '')) {
            'section_replace' => 'structural_replace',
            'section_insert' => 'additive_insert',
            'surgical' => 'surgical_copy',
            'no_change' => 'no_change',
            default => 'unclassified',
        },
        'soft_expected_path' => $scenario['soft_expected_path'] ?? null,
        'soft_expected_tools' => is_array($scenario['soft_expected_tools'] ?? null)
            ? $scenario['soft_expected_tools']
            : [],
        'classifier_version' => AWPT\Support\QueueImprovePathClassifier::VERSION,
        'session_reused' => $session_reused,
        'provider' => $provider_settings['provider'] ?? '',
        'model' => $provider_settings['model'] ?? '',
        'apply_review_safe' => !$no_apply,
        'top_level_section_count' => count($sections_before),
        'content_hash_before' => $content_hash_before,
    ] + AWPT\Support\EvaluationProvenance::collect((string) $post->post_type);

if (is_wp_error($result)) {
    $summary = [
        'scenario_id' => $canonical_id,
        'post_id' => $post_id,
        'title' => $post->post_title,
        'session_id' => $session_id,
        'turn_id' => $turn_id,
        'elapsed_s' => $elapsed,
        'meta' => $run_meta,
        'error' => [
            'code' => $result->get_error_code(),
            'message' => $result->get_error_message(),
            'data' => $result->get_error_data(),
        ],
    ];
    file_put_contents($summary_path, wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "ERROR {$result->get_error_code()}: {$result->get_error_message()}\n";
    echo "SUMMARY {$summary_path}\n";
    exit(1);
}

$act_tool_calls = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
$tool_calls = array_merge($eval_tool_calls, $act_tool_calls);
$actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];

// Evaluate isolation checks (two-step only).
$eval_propose_count = 0;
foreach ($eval_tool_calls as $call) {
    $tool = (string) ($call['tool'] ?? $call['name'] ?? '');
    if (str_starts_with($tool, 'awpt/propose-')) {
        ++$eval_propose_count;
    }
}

file_put_contents($raw_path, wp_json_encode([
    'scenario_id' => $canonical_id,
    'post_id' => $post_id,
    'session_id' => $session_id,
    'turn_id' => $turn_id,
    'meta' => $run_meta,
    'prompt' => $two_step ? AWPT\Support\ImprovePagePrompt::evaluate_text() : $prompt,
    'plan' => $plan_text,
    'tool_calls' => $tool_calls,
    'actions' => $actions,
    'turn_outcome' => $result['turn_outcome'] ?? null,
    'content' => (string) ($result['content'] ?? ''),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$tool_summary = [];
$recommended = [];
$read_patterns = [];
$unfit = [];
$tools_seen = [];
foreach ($tool_calls as $call) {
    $tool = (string) ($call['tool'] ?? $call['name'] ?? '');
    $status = (string) ($call['status'] ?? '');
    $input = is_array($call['input'] ?? null) ? $call['input'] : [];
    $output = is_array($call['output'] ?? null) ? $call['output'] : [];
    $tool_summary[] = $tool . ':' . $status;
    if ('' !== $tool) {
        $tools_seen[$tool] = true;
    }

    if ($tool === 'awpt/recommend-patterns' && $status === 'success') {
        $recs = $output['recommendations'] ?? $output['patterns'] ?? [];
        if (is_array($recs)) {
            foreach ($recs as $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $name = (string) ($rec['pattern']['name'] ?? $rec['name'] ?? '');
                if ($name !== '') {
                    $recommended[] = $name;
                }
            }
        }
    }
    if ($tool === 'awpt/read-pattern') {
        $read_patterns[] = (string) ($input['pattern_name'] ?? $input['name'] ?? '') . ':' . $status;
    }
    foreach (['pattern_unfit_code', 'pattern_fallback_reason', 'pattern_name'] as $key) {
        if (!empty($input[$key])) {
            $unfit[$key] = is_string($input[$key]) ? $input[$key] : wp_json_encode($input[$key]);
        }
    }
}

$review_safe = [
    'content_update',
    'block_attrs_update',
    'block_insert',
    'block_remove',
    'pattern_insert',
    'pattern_replace',
];
$applied = [];
$actions_repo = new AWPT\Database\ActionRepository();
foreach ($actions as $action) {
    $id = (int) ($action['id'] ?? 0);
    $status = (string) ($action['status'] ?? '');
    $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
    $op = (string) ($payload['operation'] ?? '');
    $action_post = (int) ($payload['post_id'] ?? 0);
    $entry = [
        'id' => $id,
        'status' => $status,
        'operation' => $op,
        'title' => (string) ($action['title'] ?? ''),
        'pattern_name' => (string) ($payload['pattern_name'] ?? ''),
        'pattern_unfit_code' => (string) ($payload['pattern_unfit_code'] ?? ''),
        'pattern_fallback_reason' => (string) ($payload['pattern_fallback_reason'] ?? ''),
        'composition_manifest' => is_array($payload['composition_manifest'] ?? null)
            ? $payload['composition_manifest']
            : null,
        'pattern_mode' => (string) ($payload['pattern_mode'] ?? ''),
        'payload' => [
            'operation' => $op,
            'pattern_name' => (string) ($payload['pattern_name'] ?? ''),
            'pattern_unfit_code' => (string) ($payload['pattern_unfit_code'] ?? ''),
            'pattern_mode' => (string) ($payload['pattern_mode'] ?? ''),
            'composition_manifest' => is_array($payload['composition_manifest'] ?? null)
                ? $payload['composition_manifest']
                : null,
        ],
    ];

    if (
        !$no_apply
        && $id > 0
        && $action_post === $post_id
        && in_array($op, $review_safe, true)
        && in_array($status, ['proposed', 'approved'], true)
    ) {
        $actions_repo->update_status($id, 'approved');
        $apply = new AWPT\Abilities\ApplyAction()->execute(['action_id' => $id]);
        if (is_wp_error($apply)) {
            $actions_repo->update_status($id, $status);
            $entry['apply_error'] = $apply->get_error_message();
        } else {
            $entry['status'] = 'applied';
            $entry['applied'] = true;
        }
    }
    $applied[] = $entry;
}

$classification = new AWPT\Support\QueueImprovePathClassifier()->classify(
    $applied,
    $tool_summary,
    array_values(array_unique($recommended)),
);

$first_proposal_valid = null;
foreach ($applied as $a) {
    if (($a['status'] ?? '') === 'applied' || ($a['applied'] ?? false) === true) {
        $first_proposal_valid = true;
        break;
    }
    if (!empty($a['apply_error'])) {
        $first_proposal_valid = false;
        break;
    }
    if (in_array((string) ($a['status'] ?? ''), ['proposed', 'approved'], true)) {
        $first_proposal_valid = true;
        break;
    }
}

// Soft expected-tool hits (observation only).
$expected_tools = is_array($scenario['soft_expected_tools'] ?? null) ? $scenario['soft_expected_tools'] : [];
$expected_hits = [];
$expected_misses = [];
foreach ($expected_tools as $tool_name) {
    $tool_name = (string) $tool_name;
    $hit = false;
    foreach ($tool_summary as $line) {
        if (str_starts_with((string) $line, $tool_name . ':')) {
            $hit = true;
            break;
        }
    }
    if ($hit) {
        $expected_hits[] = $tool_name;
    } else {
        $expected_misses[] = $tool_name;
    }
}

$soft_path = $scenario['soft_expected_path'] ?? null;
$path_match =
    null === $soft_path || '' === $soft_path ? null : (string) $classification['path_used'] === (string) $soft_path;

$post_after = get_post($post_id);
$content_hash_after = $post_after ? hash('sha256', (string) $post_after->post_content) : null;
$sections_after = $post_after ? AWPT\Support\PageSectionModel::from_content((string) $post_after->post_content) : [];

$summary = [
    'scenario_id' => $canonical_id,
    'scenario_title' => (string) ($scenario['title'] ?? ''),
    'scenario_family' => (string) ($scenario['family'] ?? ''),
    'post_id' => $post_id,
    'title' => $post->post_title,
    'session_id' => $session_id,
    'turn_id' => $turn_id,
    'elapsed_s' => $elapsed,
    'meta' => $run_meta,
    'top_level_section_count' => count($sections_before),
    'sections_before' => array_map(static fn(array $s): array => [
        'path' => $s['path'] ?? '',
        'role' => $s['role'] ?? '',
        'heading' => $s['heading'] ?? '',
        'preserve_by_default' => !empty($s['preserve_by_default']),
    ], $sections_before),
    'sections_after_count' => count($sections_after),
    'content_changed' => null !== $content_hash_after && !hash_equals($content_hash_before, $content_hash_after),
    'path_used' => $classification['path_used'],
    'server_materialized' => $classification['server_materialized'],
    'server_materialized_operations' => $classification['server_materialized_operations'],
    'first_proposal_valid' => $first_proposal_valid,
    'turn_outcome' => $result['turn_outcome'] ?? null,
    'tools' => $tool_summary,
    'recommended_patterns' => array_values(array_unique($recommended)),
    'read_patterns' => $read_patterns,
    'unfit_fields_seen' => $unfit,
    'actions' => $applied,
    'raw_trace_path' => $raw_path,
    'assistant_excerpt' => substr(preg_replace('/\s+/', ' ', (string) ($result['content'] ?? '')), 0, 800),
    'plan_excerpt' => mb_substr($plan_text, 0, 800),
    'scenario_observation' => [
        'soft_expected_path' => $soft_path,
        'path_matched_soft_expected' => $path_match,
        'soft_expected_tools' => $expected_tools,
        'soft_expected_tools_hit' => $expected_hits,
        'soft_expected_tools_missed' => $expected_misses,
        'two_step' => $two_step,
        'evaluate_plan_non_empty' => $two_step ? '' !== $plan_text : null,
        'evaluate_no_propose' => $two_step ? 0 === $eval_propose_count : null,
        'evaluate_propose_count' => $two_step ? $eval_propose_count : null,
        'observe' => is_array($scenario['observe'] ?? null) ? $scenario['observe'] : [],
        'hard_pass' => is_array($scenario['hard_pass'] ?? null) ? $scenario['hard_pass'] : [],
        'notes' => (string) ($scenario['notes'] ?? ''),
    ],
];

$summary['scorecard'] = new AWPT\Support\QueueImproveScorecard()->from_run_summary($summary);

file_put_contents($summary_path, wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "elapsed_s={$elapsed}\n";
echo 'outcome=' . wp_json_encode($result['turn_outcome'] ?? null) . "\n";
echo 'path=' . $classification['path_used'] . "\n";
echo 'path_soft_match=' . (null === $path_match ? 'n/a' : ($path_match ? 'yes' : 'no')) . "\n";
echo 'server_materialized=' . ($classification['server_materialized'] ? 'yes' : 'no') . "\n";
echo
    'scorecard_prepare='
        . (int) ($summary['scorecard']['prepare_change_success'] ?? 0)
        . ' replace='
        . (int) ($summary['scorecard']['propose_replace_success'] ?? 0)
        . ' freehand='
        . (!empty($summary['scorecard']['freehand_provenance']) ? 'yes' : 'no')
        . "\n"
;
if ($two_step) {
    echo 'evaluate_plan_non_empty=' . ('' !== $plan_text ? 'yes' : 'no') . "\n";
    echo 'evaluate_no_propose=' . (0 === $eval_propose_count ? 'yes' : 'no') . "\n";
}
echo 'expected_tools_hit=' . implode(',', $expected_hits) . "\n";
echo 'expected_tools_missed=' . implode(',', $expected_misses) . "\n";
echo 'tools=' . implode(',', $tool_summary) . "\n";
echo 'actions=' . count($applied) . "\n";
foreach ($applied as $i => $a) {
    echo
        'action'
            . $i
            . '='
            . wp_json_encode([
                'id' => $a['id'] ?? null,
                'status' => $a['status'] ?? null,
                'operation' => $a['operation'] ?? null,
                'pattern_name' => $a['pattern_name'] ?? null,
                'apply_error' => $a['apply_error'] ?? null,
            ])
            . "\n"
    ;
}
echo 'content=' . substr(preg_replace('/\s+/', ' ', (string) ($result['content'] ?? '')), 0, 400) . "\n";
echo "SUMMARY {$summary_path}\n";
echo "RAW {$raw_path}\n";
echo "DONE\n";
