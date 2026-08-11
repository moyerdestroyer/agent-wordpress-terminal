<?php

/**
 * Run review-queue Improve (CLI): evaluate → act by default (matches review bridge).
 *
 * Usage:
 *   wp eval-file bin/queue-improve-one.php <post_id>
 *   wp eval-file bin/queue-improve-one.php <post_id> one-shot
 *   wp eval-file bin/queue-improve-one.php <post_id> reuse-session
 *
 * Defaults to a fresh session so audit evidence is not contaminated by historical
 * tool calls. Pass reuse-session to keep the focused-post session (production UX).
 * Pass one-shot for legacy single ImprovePagePrompt turn.
 */

$cli_args = array_values(array_filter(
    array_merge($args ?? [], array_slice($GLOBALS['argv'] ?? [], 1)),
    static fn($a) => is_string($a) && $a !== '' && !str_ends_with((string) $a, 'queue-improve-one.php'),
));

$post_id = 0;
$reuse_session = false;
$one_shot = false;
$scenario_class = 'unclassified';
foreach ($cli_args as $arg) {
    if (str_starts_with((string) $arg, 'class=')) {
        $scenario_class = sanitize_key(substr((string) $arg, 6));
        continue;
    }
    if (in_array($arg, ['reuse-session', '--reuse-session'], true)) {
        $reuse_session = true;
        continue;
    }
    if (in_array($arg, ['one-shot', '--one-shot'], true)) {
        $one_shot = true;
        continue;
    }
    if (str_starts_with((string) $arg, '--')) {
        continue;
    }
    if ($post_id <= 0 && is_numeric($arg)) {
        $post_id = (int) $arg;
    }
}

if (!in_array(
    $scenario_class,
    ['unclassified', 'structural_replace', 'additive_insert', 'surgical_copy', 'no_change'],
    true,
)) {
    fwrite(STDERR, "Invalid class. Use structural_replace, additive_insert, surgical_copy, or no_change.\n");
    exit(1);
}

if ($post_id <= 0) {
    fwrite(
        STDERR,
        "Usage: wp eval-file bin/queue-improve-one.php <post_id> [class=CLASS] [one-shot] [reuse-session]\n",
    );
    exit(1);
}

$post = get_post($post_id);
if (!$post) {
    fwrite(STDERR, "Post {$post_id} not found\n");
    exit(1);
}

wp_set_current_user(1);

$sessions = new AWPT\Database\SessionRepository();
$session_reused = false;
if ($reuse_session) {
    $existing = $sessions->find_by_focus($post_id);
    if ($existing) {
        $session_id = (int) $existing['id'];
        $session_reused = true;
    } else {
        $created = $sessions->create(sprintf('Queue improve #%d', $post_id), $post_id);
        $session_id = (int) ($created['id'] ?? 0);
    }
} else {
    $created = $sessions->create(sprintf('Queue improve #%d (audit)', $post_id), $post_id);
    $session_id = (int) ($created['id'] ?? 0);
}

if ($session_id <= 0) {
    fwrite(STDERR, "Failed to create session for post {$post_id}\n");
    exit(1);
}

$run_id = gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false, false));
$base_turn = sprintf('queue-%d-%s', $post_id, $run_id);
$out_dir = WP_CONTENT_DIR . '/plugins/agent-wordpress-terminal/tmp-queue-runs';
if (!is_dir($out_dir) && !wp_mkdir_p($out_dir)) {
    $out_dir = '/tmp';
}
$summary_path = sprintf('%s/awpt-queue-%d-%s.json', $out_dir, $post_id, $run_id);
$raw_path = sprintf('%s/awpt-queue-%d-%s.raw.json', $out_dir, $post_id, $run_id);

$provider_settings = [];
if (class_exists('AWPT\\Agent\\ProviderFactory')) {
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
}
if ('' === ($provider_settings['provider'] ?? '')) {
    $provider_settings['provider'] = (string) get_option('awpt_provider', '');
}
if ('' === ($provider_settings['model'] ?? '')) {
    $provider_settings['model'] = (string) get_option('awpt_openrouter_model', '');
}

$prompt_version = $one_shot
    ? AWPT\Support\ImprovePagePrompt::PROMPT_VERSION_LEGACY
    : AWPT\Support\ImprovePagePrompt::PROMPT_VERSION_TWO_STEP;
$provenance = AWPT\Support\EvaluationProvenance::collect((string) $post->post_type);

echo
    sprintf(
        "START post=%d title=%s session=%d reused=%s mode=%s\n",
        $post_id,
        $post->post_title,
        $session_id,
        $session_reused ? 'yes' : 'no',
        $one_shot ? 'one-shot' : 'eval-act',
    )
;

$started = microtime(true);
$runtime = new AWPT\Agent\AgentRuntime();
$tool_calls = [];
$actions = [];
$plan_text = '';
$eval_turn_id = $base_turn . '-eval';
$act_turn_id = $base_turn . '-act';
$turn_id = $act_turn_id;
$result = null;

if (!$one_shot) {
    echo "PHASE evaluate turn={$eval_turn_id}\n";
    $workflow_run = new AWPT\Support\ImproveWorkflowRunner()->run($session_id, $post_id, $base_turn, $runtime);
    $eval_result = $workflow_run['evaluate'];
    if (is_wp_error($eval_result)) {
        $elapsed = round(microtime(true) - $started, 1);
        $summary = [
            'post_id' => $post_id,
            'title' => $post->post_title,
            'session_id' => $session_id,
            'turn_id' => $eval_turn_id,
            'elapsed_s' => $elapsed,
            'meta' =>
                [
                    'plugin_version' => defined('AWPT_VERSION') ? AWPT_VERSION : '',
                    'prompt_version' => $prompt_version,
                    'phase' => 'evaluate',
                    'classifier_version' => AWPT\Support\QueueImprovePathClassifier::VERSION,
                    'session_reused' => $session_reused,
                    'provider' => $provider_settings['provider'] ?? '',
                    'model' => $provider_settings['model'] ?? '',
                ] + $provenance,
            'error' => [
                'code' => $eval_result->get_error_code(),
                'message' => $eval_result->get_error_message(),
                'data' => $eval_result->get_error_data(),
            ],
        ];
        file_put_contents($summary_path, wp_json_encode($summary, JSON_PRETTY_PRINT));
        echo "ERROR {$eval_result->get_error_code()}: {$eval_result->get_error_message()}\n";
        echo "SUMMARY {$summary_path}\n";
        exit(1);
    }
    $plan_text = trim((string) $workflow_run['plan']);
    $eval_tools = is_array($eval_result['tool_calls'] ?? null) ? $eval_result['tool_calls'] : [];
    $tool_calls = array_merge($tool_calls, $eval_tools);
    echo 'plan_chars=' . strlen($plan_text) . "\n";
    echo 'PHASE act turn=' . $act_turn_id . "\n";
    $result = $workflow_run['act'];
    $turn_id = $act_turn_id;
} else {
    $turn_id = $base_turn;
    echo "PHASE one-shot turn={$turn_id}\n";
    $result = $runtime->handle_message($session_id, AWPT\Support\ImprovePagePrompt::text(), [
        'turn_id' => $turn_id,
        'focus_post_id' => $post_id,
    ]);
}

$elapsed = round(microtime(true) - $started, 1);

$run_meta =
    [
        'run_id' => $run_id,
        'scenario_class' => $scenario_class,
        'plugin_version' => defined('AWPT_VERSION') ? AWPT_VERSION : '',
        'prompt_version' => $prompt_version,
        'one_shot' => $one_shot,
        'classifier_version' => AWPT\Support\QueueImprovePathClassifier::VERSION,
        'session_reused' => $session_reused,
        'provider' => $provider_settings['provider'] ?? '',
        'model' => $provider_settings['model'] ?? '',
        'plan_chars' => strlen($plan_text),
    ] + $provenance;

if (is_wp_error($result)) {
    $summary = [
        'post_id' => $post_id,
        'title' => $post->post_title,
        'session_id' => $session_id,
        'turn_id' => $turn_id,
        'elapsed_s' => $elapsed,
        'meta' => $run_meta,
        'plan_excerpt' => mb_substr($plan_text, 0, 800),
        'error' => [
            'code' => $result->get_error_code(),
            'message' => $result->get_error_message(),
            'data' => $result->get_error_data(),
        ],
    ];
    file_put_contents($summary_path, wp_json_encode($summary, JSON_PRETTY_PRINT));
    echo "ERROR {$result->get_error_code()}: {$result->get_error_message()}\n";
    echo "SUMMARY {$summary_path}\n";
    exit(1);
}

$act_tools = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
$tool_calls = array_merge($tool_calls, $act_tools);
$actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];

// Preserve raw traces separately from derived summaries so classifiers can be replayed.
file_put_contents($raw_path, wp_json_encode([
    'post_id' => $post_id,
    'session_id' => $session_id,
    'turn_id' => $turn_id,
    'meta' => $run_meta,
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
foreach ($tool_calls as $call) {
    $tool = (string) ($call['tool'] ?? $call['name'] ?? '');
    $status = (string) ($call['status'] ?? '');
    $input = is_array($call['input'] ?? null) ? $call['input'] : [];
    $output = is_array($call['output'] ?? null) ? $call['output'] : [];
    $tool_summary[] = $tool . ':' . $status;

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

// Match reviewBridge REVIEW_SAFE_OPERATIONS (batch is proposed but not auto-applied).
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
        $id > 0
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

$top_level_section_count = count(AWPT\Support\PageSectionModel::from_content((string) $post->post_content, [
    'title' => (string) $post->post_title,
    'post_type' => (string) $post->post_type,
]));
$run_meta['top_level_section_count'] = $top_level_section_count;

$summary = [
    'post_id' => $post_id,
    'title' => $post->post_title,
    'session_id' => $session_id,
    'turn_id' => $turn_id,
    'elapsed_s' => $elapsed,
    'meta' => $run_meta,
    'plan_excerpt' => mb_substr($plan_text, 0, 800),
    'top_level_section_count' => $top_level_section_count,
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
];

$summary['scorecard'] = new AWPT\Support\QueueImproveScorecard()->from_run_summary($summary);

file_put_contents($summary_path, wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "elapsed_s={$elapsed}\n";
echo 'outcome=' . wp_json_encode($result['turn_outcome'] ?? null) . "\n";
echo 'path=' . $classification['path_used'] . "\n";
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
echo 'tools=' . implode(',', $tool_summary) . "\n";
echo 'recommended=' . implode(',', array_values(array_unique($recommended))) . "\n";
echo 'read=' . implode(',', $read_patterns) . "\n";
echo 'actions=' . count($applied) . "\n";
foreach ($applied as $i => $a) {
    echo 'action' . $i . '=' . wp_json_encode($a) . "\n";
}
echo 'content=' . substr(preg_replace('/\s+/', ' ', (string) ($result['content'] ?? '')), 0, 400) . "\n";
echo "SUMMARY {$summary_path}\n";
echo "RAW {$raw_path}\n";
echo "DONE\n";
