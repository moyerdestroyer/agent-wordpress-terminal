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
    Assert::false(ImprovePagePrompt::is_evaluate_message(ImprovePagePrompt::act_text()), 'act brief alone is not evaluate');

    $with_plan = ImprovePagePrompt::act_message("Replace FAQ at path 2\nKeep header");
    Assert::true(str_contains($with_plan, '## Plan'), 'act message embeds plan');
    Assert::true(str_contains($with_plan, 'path 2'), 'plan body preserved');

    $empty = ImprovePagePrompt::act_message('  ');
    Assert::same(ImprovePagePrompt::text(), $empty, 'empty plan falls back to legacy one-shot');
}

function test_turn_profile_improve_evaluate_is_read_only(): void {
    $profile = TurnProfile::from_message(
        ImprovePagePrompt::evaluate_text(),
        [],
        ['has_focus' => true],
    );

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
    $profile = TurnProfile::from_message(
        ImprovePagePrompt::text(),
        [],
        ['has_focus' => true],
    );
    Assert::true($profile->is_redesign(), 'legacy one-shot remains redesign');
    Assert::true($profile->uses_explore_compose_phases(), 'legacy uses explore/compose');
}

test_improve_prompt_evaluate_marker_and_act_message();
test_turn_profile_improve_evaluate_is_read_only();
test_turn_profile_legacy_improve_still_redesign();
