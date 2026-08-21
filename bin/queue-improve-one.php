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
$rollback_after = false;
$notes = '';
$model_override = '';
$provider_override = '';
$prompt_variant = 'default';
$scenario_class = 'unclassified';
foreach ($cli_args as $arg) {
    if (str_starts_with((string) $arg, 'class=')) {
        $scenario_class = sanitize_key(substr((string) $arg, 6));
        continue;
    }
    if (str_starts_with((string) $arg, 'notes=')) {
        $notes = trim(substr((string) $arg, 6));
        continue;
    }
    if (str_starts_with((string) $arg, 'model=')) {
        $model_override = trim(substr((string) $arg, 6));
        continue;
    }
    if (str_starts_with((string) $arg, 'provider=')) {
        $provider_override = sanitize_key(substr((string) $arg, 9));
        continue;
    }
    if (str_starts_with((string) $arg, 'prompt=')) {
        $prompt_variant = sanitize_key(substr((string) $arg, 7));
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
    if (in_array($arg, ['rollback', '--rollback', 'rollback-after'], true)) {
        $rollback_after = true;
        continue;
    }
    if (str_starts_with((string) $arg, '--')) {
        continue;
    }
    if ($post_id <= 0 && is_numeric($arg)) {
        $post_id = (int) $arg;
    }
}

$prompt_packs = [
    'default' => '',
    'strict_docs' =>
        "Hard constraints:\n"
        . "- This is a whole-document documentation-page replace "
        . "(op=pattern_replace, paths=[\"*\"], pattern_name=civicpress/layout-page-documentation).\n"
        . "- Do not choose op=batch or op=none for a documentation-page request.\n"
        . "- Do not use section-only patterns (TOC, cards, headers) as the page layout.\n"
        . "- After staging, zero instructional filler may remain "
        . "(no “Section heading”, “side navigation”, “component page”, “Read the full documentation”).\n"
        . "- Do not paste the same paragraph into multiple slots; map distinct page facts once.",
    'surgical_only' =>
        "Hard constraints:\n"
        . "- Use only awpt/propose-block-batch-update (set/remove/insert). No pattern-replace or pattern-insert.\n"
        . "- Prefer ≤3 changes per batch. Do not set heading `level` on core/paragraph.\n"
        . "- For rich text use html/text fields, not attrs.content.",
    'minimal_brief' =>
        "Keep the plan to one unit and one propose call. Prefer the smallest valid mutation.",
];
if (!isset($prompt_packs[$prompt_variant])) {
    fwrite(STDERR, "Unknown prompt= variant. Use: default, strict_docs, surgical_only, minimal_brief\n");
    exit(1);
}
$extra_prompt = trim($prompt_packs[$prompt_variant]);
if ('' !== $extra_prompt) {
    $notes = trim($notes . ('' !== $notes ? "\n\n" : '') . $extra_prompt);
}

$previous_model = null;
$previous_openai_model = null;
$previous_provider = null;
if ('' !== $provider_override) {
    if (!in_array($provider_override, ['openrouter', 'openai'], true)) {
        fwrite(STDERR, "Invalid provider. Use openrouter or openai.\n");
        exit(1);
    }
    $previous_provider = (string) get_option('awpt_provider', '');
    update_option('awpt_provider', $provider_override, false);
}
if ('' !== $model_override) {
    if ('openai' === $provider_override || 'openai' === (string) get_option('awpt_provider', '')) {
        $previous_openai_model = (string) get_option('awpt_openai_model', '');
        update_option('awpt_openai_model', $model_override, false);
    } else {
        $previous_model = (string) get_option('awpt_openrouter_model', '');
        update_option('awpt_openrouter_model', $model_override, false);
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
        "Usage: wp eval-file bin/queue-improve-one.php <post_id> [notes=...] [class=CLASS] [rollback] [one-shot] [reuse-session]\n",
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
if ('' !== $model_override) {
    $provider_settings['model'] = $model_override;
}
if ('' !== $provider_override) {
    $provider_settings['provider'] = $provider_override;
}
if ('openai' === ($provider_settings['provider'] ?? '') && '' === $model_override) {
    $provider_settings['model'] = trim((string) get_option('awpt_openai_model', ''));
    if ('' === $provider_settings['model']) {
        $provider_settings['model'] = (string) apply_filters(
            'awpt_openai_model',
            AWPT\Agent\OpenAIProvider::DEFAULT_MODEL,
        );
    }
}

register_shutdown_function(static function () use (
    $previous_model,
    $previous_openai_model,
    $model_override,
    $previous_provider,
    $provider_override,
): void {
    if (null !== $previous_model && '' !== $model_override) {
        update_option('awpt_openrouter_model', $previous_model, false);
    }
    if (null !== $previous_openai_model && '' !== $model_override) {
        update_option('awpt_openai_model', $previous_openai_model, false);
    }
    if (null !== $previous_provider && '' !== $provider_override) {
        update_option('awpt_provider', $previous_provider, false);
    }
});

$prompt_version = $one_shot
    ? AWPT\Support\ImprovePagePrompt::PROMPT_VERSION_LEGACY
    : AWPT\Support\ImprovePagePrompt::PROMPT_VERSION_TWO_STEP;
$provenance = AWPT\Support\EvaluationProvenance::collect((string) $post->post_type);

echo
    sprintf(
        "START post=%d title=%s session=%d reused=%s mode=%s model=%s prompt=%s\n",
        $post_id,
        $post->post_title,
        $session_id,
        $session_reused ? 'yes' : 'no',
        $one_shot ? 'one-shot' : 'eval-act',
        $provider_settings['model'] ?? '',
        $prompt_variant,
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
    $workflow_run = new AWPT\Support\ImproveWorkflowRunner()->run(
        $session_id,
        $post_id,
        $base_turn,
        $runtime,
        ['evaluation_context' => $notes, 'auto_continue' => true],
    );
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
    $act_results = is_array($workflow_run['acts'] ?? null) ? $workflow_run['acts'] : [];
    if ([] === $act_results && null !== ($workflow_run['act'] ?? null)) {
        $act_results = [$workflow_run['act']];
    }
    echo 'PHASE act turns=' . count($act_results) . "\n";
    foreach ($act_results as $act_index => $act_result) {
        $turn_id = $base_turn . '-act' . ($act_index > 0 ? '-' . $act_index : '');
        echo "PHASE act turn={$turn_id}\n";
        if (is_wp_error($act_result)) {
            $result = $act_result;
            break;
        }
        $result = $act_result;
        $act_tools = is_array($act_result['tool_calls'] ?? null) ? $act_result['tool_calls'] : [];
        $tool_calls = array_merge($tool_calls, $act_tools);
        $act_actions = is_array($act_result['actions'] ?? null) ? $act_result['actions'] : [];
        $actions = array_merge($actions, $act_actions);
    }
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
        'prompt_variant' => $prompt_variant,
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

if ($one_shot) {
    $act_tools = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
    $tool_calls = array_merge($tool_calls, $act_tools);
    $actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];
}

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
            $after = (string) get_post_field('post_content', $post_id);
            $plain = preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode(wp_strip_all_tags($after), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ) ?? '';
            $filler_re =
                '/Section heading \(h[23]\)|Subsection heading|page heading communicates|particulars of your body copy|inverted pyramid|Keep each section and subsection focused|Use the side navigation menu|Read the full documentation|side navigation on the component page|on the component page/i';
            $max_repeat = 1;
            $worst = '';
            $plain_len = strlen($plain);
            for ($i = 0; $i < min($plain_len, 2500); $i += 16) {
                $chunk = substr($plain, $i, 32);
                if (strlen(trim($chunk)) < 16) {
                    continue;
                }
                $n = substr_count($plain, $chunk);
                if ($n > $max_repeat) {
                    $max_repeat = $n;
                    $worst = $chunk;
                }
            }
            $entry['content_audit'] = [
                'has_pattern_meta' => str_contains($after, 'patternName') || str_contains($after, 'layout-page-'),
                'instructional_filler' => (bool) preg_match($filler_re, $plain),
                'duplication_repeats' => $max_repeat,
                'duplication_chunk' => $worst,
                'duplication_suspect' => $max_repeat >= 4,
                'quality_ok' => !preg_match($filler_re, $plain) && $max_repeat < 4,
                'plain_len' => $plain_len,
                'preview' => mb_substr($plain, 0, 240),
            ];
            // Persist a browser-viewable snapshot of what applied (before optional rollback).
            $preview_dir = $out_dir . '/previews';
            if (!is_dir($preview_dir)) {
                wp_mkdir_p($preview_dir);
            }
            $preview_path = sprintf('%s/post-%d-action-%d.html', $preview_dir, $post_id, $id);
            $preview_html =
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Staged #'
                . $post_id
                . '</title><style>body{font:16px/1.5 Georgia,serif;max-width:52rem;margin:2rem auto;padding:0 1rem}'
                . '.meta{color:#57534e;font:14px/1.4 system-ui,sans-serif;margin-bottom:1.5rem}</style></head><body>'
                . '<p class="meta">Staged preview for post #'
                . (int) $post_id
                . ' · action #'
                . (int) $id
                . ' · '
                . esc_html((string) ($payload['pattern_name'] ?? $op))
                . '</p>'
                . apply_filters('the_content', $after)
                . '</body></html>';
            file_put_contents($preview_path, $preview_html);
            $entry['content_audit']['preview_html'] = $preview_path;
            if ($rollback_after) {
                $rollback = new AWPT\Abilities\ApplyAction()->execute([
                    'action_id' => $id,
                    'operation' => 'rollback',
                ]);
                // Prefer REST-shaped update_status path used elsewhere when execute lacks operation.
                if (is_wp_error($rollback)) {
                    $req = new WP_REST_Request('POST', '/awpt/v1/actions/' . $id);
                    $req->set_param('operation', 'rollback');
                    $res = rest_do_request($req);
                    $entry['rolled_back'] = 200 === (int) $res->get_status()
                        && 'rolled_back' === (string) (($res->get_data()['status'] ?? ''));
                    if (!$entry['rolled_back']) {
                        $entry['rollback_error'] = wp_json_encode($res->get_data());
                    }
                } else {
                    $entry['rolled_back'] = true;
                    $entry['status'] = 'rolled_back';
                }
            }
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

$cache_stats = new AWPT\Database\ProviderCallRepository()->session_token_totals($session_id);
$run_meta['cache'] = $cache_stats;

$summary = [
    'post_id' => $post_id,
    'title' => $post->post_title,
    'session_id' => $session_id,
    'turn_id' => $turn_id,
    'elapsed_s' => $elapsed,
    'notes' => $notes,
    'meta' => $run_meta,
    'cache' => $cache_stats,
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
