<?php

/**
 * Improve evaluate → act prompts and turn isolation.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\TurnProfile;
use AWPT\Support\ImprovePagePrompt;

function test_improve_prompt_evaluate_marker_and_act_message(): void {
    $eval = ImprovePagePrompt::evaluate_text();
    Assert::true(ImprovePagePrompt::is_evaluate_message($eval), 'evaluate text carries marker');
    Assert::true(str_starts_with(trim($eval), ImprovePagePrompt::EVALUATE_MARKER), 'marker is prefix');
    Assert::false(ImprovePagePrompt::is_evaluate_message(ImprovePagePrompt::text()), 'legacy one-shot is not evaluate');
    Assert::false(
        ImprovePagePrompt::is_evaluate_message(ImprovePagePrompt::act_text()),
        'act brief alone is not evaluate',
    );

    $act = ImprovePagePrompt::act_text();
    Assert::true(ImprovePagePrompt::is_act_message($act), 'act text carries act marker');
    Assert::true(str_starts_with(trim($act), ImprovePagePrompt::ACT_MARKER), 'act marker is prefix');
    Assert::false(ImprovePagePrompt::is_act_message($eval), 'evaluate is not act');
    Assert::false(ImprovePagePrompt::is_evaluate_message($act), 'act is not evaluate');

    $with_plan = ImprovePagePrompt::act_message("Replace FAQ at path 2\nKeep header");
    Assert::true(ImprovePagePrompt::is_act_message($with_plan), 'act message keeps marker');
    Assert::true(str_contains($with_plan, '## Plan'), 'act message embeds plan');
    Assert::true(str_contains($with_plan, 'path 2'), 'plan body preserved');
    Assert::true(str_contains($with_plan, 'authoritative') || str_contains($with_plan, 'Trust'), 'act trusts the plan');
    Assert::true(str_contains($with_plan, 'update_block'), 'act explains combined same-path mutations');
    Assert::true(
        str_contains($with_plan, 'one non-insertion mutation'),
        'act makes the per-path batch invariant explicit',
    );

    $empty = ImprovePagePrompt::act_message('  ');
    Assert::same(ImprovePagePrompt::text(), $empty, 'empty plan falls back to legacy one-shot');
}

function test_turn_profile_improve_evaluate_is_read_only(): void {
    $profile = TurnProfile::from_message(ImprovePagePrompt::evaluate_text(), [], ['has_focus' => true]);

    Assert::true($profile->is_improve_evaluate(), 'flag is_improve_evaluate');
    Assert::false($profile->is_redesign(), 'evaluate is not redesign compose mode');
    Assert::false($profile->uses_explore_compose_phases(), 'no explore→compose phase machine');
    Assert::same(TurnProfile::TOOL_INVESTIGATE, $profile->tool_profile, 'investigate tool profile');

    $allow = $profile->tool_allowlist();
    Assert::true(in_array('awpt/read-block-tree', $allow, true), 'can read block tree');
    Assert::true(in_array('awpt/analyze-page', $allow, true), 'can analyze page');
    Assert::true(in_array('awpt/recommend-patterns', $allow, true), 'can recommend patterns');
    Assert::false(in_array('awpt/list-patterns', $allow, true), 'evaluate skips list-patterns thrash');
    Assert::false(in_array('awpt/read-pattern', $allow, true), 'evaluate skips deep pattern reads');
    Assert::false(in_array('awpt/propose-pattern-replace', $allow, true), 'no propose replace');
    Assert::false(in_array('awpt/propose-content-update', $allow, true), 'no propose content update');
    Assert::false(in_array('awpt/propose-pattern-insert', $allow, true), 'no propose insert');
    Assert::false(in_array('awpt/propose-block-batch-update', $allow, true), 'no batch propose');
    Assert::true(count($allow) <= 12, 'evaluate allowlist stays small');

    foreach ($allow as $tool) {
        Assert::false(
            str_starts_with((string) $tool, 'awpt/propose-'),
            'no propose tools on evaluate allowlist: ' . $tool,
        );
    }
}

function test_turn_profile_legacy_improve_still_redesign(): void {
    $profile = TurnProfile::from_message(ImprovePagePrompt::text(), [], ['has_focus' => true]);
    Assert::true($profile->is_redesign(), 'legacy one-shot remains redesign');
    Assert::true($profile->uses_explore_compose_phases(), 'legacy uses explore/compose');
}

function test_turn_profile_improve_act_trusts_plan_isolation(): void {
    $msg = ImprovePagePrompt::act_message("## Plan\n- batch/attrs on path 0.0\n- keep path 1");
    $profile = TurnProfile::from_message($msg, [], ['has_focus' => true]);

    Assert::true($profile->is_improve_act(), 'is_improve_act');
    Assert::false($profile->is_improve_evaluate(), 'not evaluate');
    Assert::true($profile->content_edit_turn, 'act is content edit');
    Assert::true($profile->uses_explore_compose_phases(), 'act uses explore→compose');
    Assert::false($profile->auto_retrieve_knowledge, 'no knowledge thrash on act');

    $explore = $profile->explore_allowlist();
    Assert::true(in_array('awpt/read-block-tree', $explore, true), 'can re-read tree');
    Assert::true(in_array('awpt/get-block', $explore, true), 'can get-block for fingerprints');
    Assert::true(in_array('awpt/prepare-pattern-change', $explore, true), 'can prepare section');
    Assert::false(in_array('awpt/find-abilities', $explore, true), 'no find-abilities thrash');
    Assert::false(in_array('awpt/list-blocks', $explore, true), 'no list-blocks thrash');
    Assert::false(in_array('awpt/read-theme-file', $explore, true), 'no theme-file thrash');
    Assert::true(count($explore) <= 8, 'act explore stays small');

    $compose = $profile->compose_allowlist();
    Assert::same('awpt/propose-block-batch-update', $compose[0] ?? null, 'batch preferred first');
    Assert::true(in_array('awpt/propose-pattern-replace', $compose, true), 'replace available');
    Assert::true(in_array('awpt/propose-content-update', $compose, true), 'freehand still last-resort');
    $freehand_pos = array_search('awpt/propose-content-update', $compose, true);
    $batch_pos = array_search('awpt/propose-block-batch-update', $compose, true);
    Assert::true(is_int($freehand_pos) && is_int($batch_pos) && $batch_pos < $freehand_pos, 'batch before freehand');
}

function test_discovery_policy_improve_act_composes_early_when_plan_has_paths(): void {
    $policy = new AWPT\Agent\DiscoveryPolicy();
    $plan_msg = ImprovePagePrompt::act_message('Fix heading at path 0.0 with batch/attrs');
    $decision = $policy->decide($plan_msg, [], [], 0, ['content_turn' => true, 'improve_act' => true]);

    Assert::true($decision['compose'], 'act composes when plan embeds paths');
    Assert::true(
        str_contains($decision['reason'], 'plan') || str_contains($decision['reason'], 'authoritative'),
        'reason cites plan',
    );
}

function test_discovery_policy_improve_act_requires_named_preparation(): void {
    $policy = new AWPT\Agent\DiscoveryPolicy();
    $plan_msg = ImprovePagePrompt::act_message(
        'Use prepare-pattern-change mode=insert at path 2, then propose-pattern-insert.',
    );
    $before = $policy->decide($plan_msg, [], [], 30, ['content_turn' => true, 'improve_act' => true]);
    Assert::false($before['compose'], 'a prepared plan cannot skip its bound preparation');

    $prepare = [[
        'tool' => 'awpt/prepare-pattern-change',
        'status' => 'success',
        'output' => ['mode' => 'insert', 'preparation_id' => 'prep-1'],
    ]];
    $after = $policy->decide($plan_msg, $prepare, $prepare, 30, [
        'content_turn' => true,
        'improve_act' => true,
    ]);
    Assert::true($after['compose'], 'successful bound preparation opens composition');
}

function test_improve_prompt_expand_slash_plan_and_improve(): void {
    $plan = ImprovePagePrompt::expand_slash_command('/plan');
    Assert::true(is_array($plan), 'plan expands');
    Assert::same('plan', $plan['command'] ?? null, 'command plan');
    Assert::true(
        ImprovePagePrompt::is_evaluate_message((string) ($plan['message'] ?? '')),
        'plan expands to evaluate marker',
    );

    $with_notes = ImprovePagePrompt::expand_slash_command('/evaluate fix FAQ headings only');
    Assert::true(is_array($with_notes), 'evaluate alias expands');
    Assert::true(str_contains((string) ($with_notes['message'] ?? ''), 'FAQ headings'), 'operator notes are appended');
    Assert::true(
        ImprovePagePrompt::is_evaluate_message((string) ($with_notes['message'] ?? '')),
        'notes keep evaluate isolation',
    );

    $improve = ImprovePagePrompt::expand_slash_command('/improve');
    Assert::true(is_array($improve), 'improve expands');
    Assert::same('improve', $improve['command'] ?? null, 'command improve');
    Assert::true(
        ImprovePagePrompt::is_evaluate_message((string) ($improve['message'] ?? '')),
        'server-side improve starts the canonical evaluate phase',
    );
    Assert::true(
        str_contains((string) ($improve['message'] ?? ''), 'active-theme patterns')
        || str_contains((string) ($improve['message'] ?? ''), 'prepare-pattern-change'),
        'improve uses the structural evaluation brief',
    );

    Assert::true(null === ImprovePagePrompt::expand_slash_command('/focus 847'), 'other slashes stay null');
    Assert::true(null === ImprovePagePrompt::expand_slash_command('plan this page'), 'plain text stays null');
}

test_improve_prompt_evaluate_marker_and_act_message();
test_turn_profile_improve_evaluate_is_read_only();
test_turn_profile_legacy_improve_still_redesign();
test_turn_profile_improve_act_trusts_plan_isolation();
test_discovery_policy_improve_act_composes_early_when_plan_has_paths();
test_discovery_policy_improve_act_requires_named_preparation();
test_improve_prompt_expand_slash_plan_and_improve();
