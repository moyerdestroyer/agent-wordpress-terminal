<?php

/**
 * Improve evaluate → act prompts and turn isolation.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\AgentWorkContextService;
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
    Assert::true(str_contains($with_plan, 'kind set'), 'act names batch kind set');
    Assert::true(str_contains($with_plan, 'propose-block-batch-update'), 'act names the surgical batch tool');
    Assert::true(str_contains($with_plan, 'the server prepares'), 'act says pattern propose tools prepare internally');
    Assert::true(str_contains($act, 'Do not re-discover the design system'), 'act does not reopen design discovery');
    Assert::true(str_contains($eval, 'Improve this focused page'), 'evaluate is an improve request');
    Assert::true(str_contains($eval, 'Do not stage changes'), 'evaluate is plan-only');
    Assert::true(str_contains($eval, 'short plan'), 'evaluate asks for a plan');
    Assert::false(str_contains($eval, 'awpt/read-block-tree'), 'evaluate brief does not name tools');
    Assert::false(str_contains($eval, 'awpt-units'), 'unit schema lives in the system module');
    Assert::false(str_contains($eval, 'least-destructive'), 'evaluate must not prescribe an op recipe');
    Assert::false(str_contains($eval, 'heading level'), 'evaluate must not prescribe heading-level work');
    Assert::false(
        str_contains($eval, 'prefer awpt/read-block-tree for top_level_sections'),
        'evaluate must not prefer outline-only reads',
    );
    $empty = ImprovePagePrompt::act_message('  ');
    Assert::same(ImprovePagePrompt::text(), $empty, 'empty plan falls back to legacy one-shot');
}

function test_improve_prompt_review_brief_stays_a_simple_request(): void {
    $brief = ImprovePagePrompt::review_brief();
    Assert::true(str_contains($brief, 'Improve this page'), 'review brief is an improve request');
    Assert::false(str_contains($brief, 'formatting'), 'review brief does not mandate formatting');
    Assert::false(str_contains($brief, 'layout'), 'review brief does not mandate layout');
    Assert::false(str_contains($brief, "\n- "), 'review brief is not a checklist');

    $message = ImprovePagePrompt::review_evaluate_message(829, 'LASLI');
    Assert::true(ImprovePagePrompt::is_evaluate_message($message), 'review evaluate stays isolated');
    Assert::true(str_contains($message, 'Focused post: #829'), 'review evaluate names the post');
    Assert::true(str_contains($message, 'LASLI'), 'review evaluate names the title');
    Assert::true(str_contains($message, $brief), 'review evaluate includes the one-click request');

    $untitled = ImprovePagePrompt::review_evaluate_message(12, '   ');
    Assert::true(str_contains($untitled, 'Untitled'), 'empty titles fall back');

    $with_notes = ImprovePagePrompt::review_evaluate_message(829, 'LASLI', 'Keep the PDF links');
    Assert::true(str_contains($with_notes, '## Reviewer request'), 'operator notes are labeled');
    Assert::true(str_contains($with_notes, 'Keep the PDF links'), 'operator notes are appended');
}

function test_turn_profile_improve_evaluate_is_read_only(): void {
    $profile = TurnProfile::from_message(ImprovePagePrompt::evaluate_text(), [], ['has_focus' => true]);

    Assert::true($profile->is_improve_evaluate(), 'flag is_improve_evaluate');
    Assert::false($profile->is_redesign(), 'evaluate is not redesign compose mode');
    Assert::false($profile->uses_explore_compose_phases(), 'no explore→compose phase machine');
    Assert::same(TurnProfile::TOOL_INVESTIGATE, $profile->tool_profile, 'investigate tool profile');

    $allow = $profile->tool_allowlist();
    Assert::true(in_array('awpt/read-block-tree', $allow, true), 'can read block tree');
    Assert::true(in_array('awpt/get-block', $allow, true), 'evaluate can get-block a named path');
    Assert::false(in_array('awpt/analyze-page', $allow, true), 'evaluate does not need a second page read');
    Assert::true(in_array('awpt/recommend-patterns', $allow, true), 'can recommend patterns');
    Assert::true(in_array('awpt/read-design-system', $allow, true), 'can read the compiled design system');
    Assert::false(in_array('awpt/list-patterns', $allow, true), 'evaluate skips list-patterns thrash');
    Assert::false(in_array('awpt/read-pattern', $allow, true), 'evaluate skips deep pattern reads');
    Assert::false(in_array('awpt/propose-pattern-replace', $allow, true), 'no propose replace');
    Assert::false(in_array('awpt/propose-content-update', $allow, true), 'no propose content update');
    Assert::false(in_array('awpt/propose-pattern-insert', $allow, true), 'no propose insert');
    Assert::false(in_array('awpt/propose-block-batch-update', $allow, true), 'no batch propose');
    Assert::true(count($allow) <= 11, 'evaluate allowlist stays small');

    foreach ($allow as $tool) {
        Assert::false(
            str_starts_with((string) $tool, 'awpt/propose-'),
            'no propose tools on evaluate allowlist: ' . $tool,
        );
    }
}

function test_improve_evaluate_work_context_is_design_aware_editing(): void {
    $message = ImprovePagePrompt::evaluate_text();
    $profile = TurnProfile::from_message($message, [], ['has_focus' => true, 'has_open_incidents' => true]);
    $context = new AgentWorkContextService()->compile($message, $profile);

    Assert::same(
        'edit',
        $context['work_type'] ?? '',
        'Improve evaluation remains edit work even with an open incident',
    );
    Assert::same(
        ['awpt/read-design-system', 'awpt/read-domain-guidance'],
        $context['workflow']['evidence_gates'][0]['abilities'] ?? [],
        'Improve evaluation should gate on the design system and scoped guidance',
    );
    Assert::true(
        '' !== (string) ($context['design_authority']['context_hash'] ?? ''),
        'Improve work context should carry stable design provenance',
    );
    Assert::same('evaluate', $context['design_scope'] ?? '', 'Improve evaluation uses the evaluate design scope');
    Assert::same(
        'evaluate',
        $context['design_detail'] ?? '',
        'Improve evaluation gets the pattern-aware evaluate slice',
    );
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
    Assert::false(in_array('awpt/prepare-pattern-change', $explore, true), 'prepare is not on the act hot path');
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
    Assert::true($before['compose'], 'pattern propose auto-prepares; act can compose from the plan');
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
        str_contains((string) ($improve['message'] ?? ''), 'Do not stage changes'),
        'improve uses the evaluate-only brief',
    );

    Assert::true(null === ImprovePagePrompt::expand_slash_command('/focus 847'), 'other slashes stay null');
    Assert::true(null === ImprovePagePrompt::expand_slash_command('plan this page'), 'plain text stays null');
}

function test_improve_prompt_parses_units_and_scopes_act_message(): void {
    $plan =
        "## Plan\n\nMerge links.\n\nThen add headings.\n\n```awpt-units\n"
        . '[{"id":"links","title":"Merge split links","op":"batch","paths":["2","3","4"],'
        . '"carry_forward":["/resources/insurer-member-lookup"],"do_not":["0","1"]},'
        . '{"id":"headings","title":"Insert H2s","op":"batch","paths":["0"]}]'
        . "\n```\n";
    $units = ImprovePagePrompt::units_from_plan($plan);
    Assert::same(2, count($units), 'both units parsed');
    Assert::same('links', $units[0]['id'] ?? null, 'first unit id');
    Assert::same(['2', '3', '4'], $units[0]['paths'] ?? null, 'first unit paths');

    $act = ImprovePagePrompt::act_message_for_unit($units[0], $plan);
    Assert::true(ImprovePagePrompt::is_act_message($act), 'unit act keeps marker');
    Assert::true(str_contains($act, '```awpt-unit'), 'unit act embeds one unit');
    Assert::false(str_contains($act, '## Plan'), 'act relies on durable evaluate history instead of resending it');
    Assert::true(str_contains($act, 'Merge split links'), 'unit title present');
    Assert::false(str_contains($act, 'Then add headings'), 'sibling plan prose is not duplicated in the act message');
    Assert::true(
        preg_match('/```awpt-unit\s*(\{.*?\})\s*```/s', $act, $unit_fence) === 1
        && !str_contains($unit_fence[1], '"id":"headings"'),
        'sibling unit JSON stays out of the awpt-unit fence',
    );
    Assert::same('batch', ImprovePagePrompt::unit_op_from_act_message($act), 'op recoverable from act brief');

    $pattern = ImprovePagePrompt::normalize_unit([
        'op' => 'pattern_replace',
        'paths' => ['0'],
        'title' => 'Doc layout',
        'pattern_name' => 'civicpress/layout-page-documentation',
        'brief' => 'Replace root with documentation layout',
    ]);
    Assert::same(
        'civicpress/layout-page-documentation',
        $pattern['pattern_name'] ?? '',
        'pattern_name is preserved on units',
    );
    Assert::true(ImprovePagePrompt::unit_is_complete($pattern), 'complete pattern unit');
    Assert::false(ImprovePagePrompt::unit_is_complete([
        'op' => 'pattern_replace',
        'paths' => [],
        'title' => 'Doc layout',
        'pattern_name' => 'civicpress/layout-page-documentation',
        'brief' => 'Replace entire page with documentation layout',
    ]), 'empty paths stay incomplete (no silent default to 0)');
    Assert::false(ImprovePagePrompt::unit_is_complete([
        'op' => 'pattern_replace',
        'paths' => ['*'],
        'title' => 'Doc layout',
        'pattern_name' => 'civicpress/layout-page-documentation',
        'brief' => 'Replace entire page with documentation layout',
    ]), 'star is not a silent full-page alias');
    Assert::same(
        ['document'],
        ImprovePagePrompt::apply_unit_defaults(ImprovePagePrompt::normalize_unit([
            'op' => 'pattern_replace',
            'paths' => ['document'],
            'pattern_name' => 'civicpress/layout-page-documentation',
            'brief' => 'Replace root',
        ]))['paths'] ?? null,
        'document alias remains an explicit document target',
    );
    Assert::false(ImprovePagePrompt::unit_is_complete([
        'op' => 'pattern_replace',
        'paths' => ['document-body'],
        'title' => 'Doc layout',
        'pattern_name' => 'civicpress/layout-page-documentation',
        'brief' => 'Replace page',
    ]), 'invented path tokens are incomplete');
    Assert::false(ImprovePagePrompt::unit_is_complete([
        'op' => 'pattern_replace',
        'paths' => [],
        'title' => 'Doc layout',
    ]), 'pattern unit without pattern_name is incomplete');
    Assert::false(ImprovePagePrompt::unit_is_complete([
        'op' => 'batch',
        'paths' => ['0'],
    ]), 'batch without brief or title is incomplete');
    $nits = ImprovePagePrompt::units_completeness_nits([
        [
            'op' => 'pattern_replace',
            'paths' => ['0'],
            'brief' => 'Replace page',
            'pattern_name' => '',
        ],
    ]);
    Assert::true([] !== $nits && str_contains($nits[0], 'pattern_name'), 'nits name missing pattern_name');
    $empty_path_nits = ImprovePagePrompt::units_completeness_nits([
        [
            'op' => 'pattern_replace',
            'paths' => [],
            'brief' => 'Replace entire page',
            'pattern_name' => 'civicpress/layout-page-documentation',
        ],
    ]);
    Assert::true([] !== $empty_path_nits && str_contains($empty_path_nits[0], 'paths'), 'nits reject empty paths');
    $merged = ImprovePagePrompt::merge_repaired_units_into_plan(
        "## Plan\n\n```json\n[{\"op\":\"pattern_replace\",\"paths\":[],\"pattern_name\":\"\"}]\n```",
        "```awpt-units\n"
        . '[{"op":"pattern_replace","paths":["0"],"brief":"Replace page",'
        . '"pattern_name":"civicpress/layout-page-documentation"}]'
        . "\n```",
    );
    Assert::true(str_contains($merged, 'layout-page-documentation'), 'repair merges pattern_name');
    Assert::true(str_contains($merged, '```awpt-units'), 'repair uses awpt-units fence');

    Assert::true(
        ImprovePagePrompt::is_fallback_evaluate_plan(
            '_Plan finalized from verified evidence after evaluate tool budget was exhausted._',
        ),
        'exhausted-budget stub is detected',
    );
    Assert::same(
        [],
        ImprovePagePrompt::units_from_plan(
            "## Execution plan\n\n### Recommended next ops\n- No change if evidence shows the page is already fine.\n\n_Plan finalized from verified evidence after evaluate tool budget was exhausted._",
        ),
        'fallback stub without units fence yields no units',
    );
    $fallback_with_units = ImprovePagePrompt::units_from_plan(
        "## Execution plan\n\n### Recommended next ops\n- Replace path 0.\n\n"
        . "- No change if evidence shows the page is already fine.\n\n"
        . "_Plan finalized from verified evidence after evaluate tool budget was exhausted._\n\n"
        . "```awpt-units\n"
        . '[{"id":"unit","title":"Apply recommended theme pattern","op":"pattern_replace",'
        . '"paths":["0"],"pattern_name":"civicpress/layout-page-documentation",'
        . '"brief":"Replace path 0 with civicpress/layout-page-documentation"}]'
        . "\n```",
    );
    Assert::same([], $fallback_with_units, 'fallback remains non-executable even when it contains a units fence');

    $legacy = ImprovePagePrompt::units_from_plan("## Plan\n- Keep path 0\n- Replace path 2");
    Assert::same([], $legacy, 'unstructured markdown cannot become an executable unit');

    $wrapped =
        "## Evaluation\n\nCleanup then intro.\n\n```json\n"
        . '{"awpt-units":['
        . '{"op":"batch","unit":1,"label":"FAQ structural cleanup","paths":["0","1"],'
        . '"description":"Fix casing and group attrs","carry_forward":["95814"],"do_not":["Do not change answers"]},'
        . '{"op":"pattern_insert","unit":2,"label":"Add introductory paragraph","paths":[]}'
        . ']}'
        . "\n```\n";
    $from_wrapper = ImprovePagePrompt::units_from_plan($wrapped);
    Assert::same(2, count($from_wrapper), 'json wrapper {awpt-units:[]} parses as two units');
    Assert::same('unit-1', $from_wrapper[0]['id'] ?? null, 'unit number becomes id');
    Assert::same('FAQ structural cleanup', $from_wrapper[0]['title'] ?? null, 'label becomes title');
    Assert::same(['0', '1'], $from_wrapper[0]['paths'] ?? null, 'wrapper paths survive');
    Assert::same('pattern_insert', $from_wrapper[1]['op'] ?? null, 'second wrapper unit kept');
    $wrapper_act = ImprovePagePrompt::act_message_for_unit($from_wrapper[0]);
    Assert::false(str_contains($wrapper_act, 'Add introductory paragraph'), 'sibling wrapped unit stays out of act');

    $with_changes = ImprovePagePrompt::normalize_unit([
        'op' => 'batch',
        'label' => 'Fix heading hierarchy and heading copy',
        'paths' => ['0.0', '1.0'],
        'changes' => 'Set heading level to 2 on all paths; update_block attrs+content on 0.0 for Can→can.',
        'do_not' => ['answer paragraphs'],
    ]);
    Assert::true(
        str_contains($with_changes['brief'], 'Set heading level to 2'),
        'changes must survive into the act brief',
    );
    $changes_act = ImprovePagePrompt::act_message_for_unit($with_changes);
    Assert::true(
        str_contains($changes_act, 'Set heading level to 2'),
        'act unit JSON must include the dropped changes text',
    );

    $from_target = ImprovePagePrompt::normalize_unit([
        'op' => 'pattern_replace',
        'target_path' => '13',
        'expected_fingerprint' => str_repeat('a', 64),
        'changes' => 'Flatten section 13',
    ]);
    Assert::same(['13'], $from_target['paths'] ?? null, 'target_path becomes paths');
    Assert::same(str_repeat('a', 64), $from_target['expected_fingerprint'] ?? '', 'fingerprint is kept');
    Assert::true(str_contains((string) ($from_target['brief'] ?? ''), 'Flatten section 13'), 'changes survive');
}

function test_improve_prompt_refreshes_stale_evaluate_body(): void {
    $stale =
        ImprovePagePrompt::EVALUATE_MARKER
        . "\nEvaluate this focused page.\n"
        . "1. Read the page (prefer awpt/read-block-tree for top_level_sections).\n\n"
        . "## Review queue context\nFocused post: #829\nFocused title: LASLI\n\n"
        . "Assess the whole page, not just the first visible defect.\n\n"
        . "## Reviewer request\nKeep the PDF links";
    $refreshed = ImprovePagePrompt::refresh_evaluate_message($stale);
    Assert::true(ImprovePagePrompt::is_evaluate_message($refreshed), 'refresh keeps evaluate marker');
    Assert::true(str_contains($refreshed, 'Do not stage changes'), 'refresh injects the current evaluate brief');
    Assert::false(
        str_contains($refreshed, 'prefer awpt/read-block-tree for top_level_sections'),
        'stale outline-only instruction must not survive',
    );
    Assert::true(str_contains($refreshed, 'Focused post: #829'), 'review-queue suffix is preserved');
    Assert::true(
        str_contains($refreshed, ImprovePagePrompt::review_brief()),
        'refresh rebuilds the current review brief',
    );
    Assert::false(
        str_contains($refreshed, 'Assess the whole page, not just the first visible defect.'),
        'stale assess-only review brief must not survive',
    );
    Assert::true(str_contains($refreshed, 'Keep the PDF links'), 'operator notes survive refresh');
    Assert::same('hello', ImprovePagePrompt::refresh_evaluate_message('hello'), 'non-evaluate messages pass through');
}

function test_turn_profile_batch_unit_narrows_compose_tools(): void {
    $unit = ImprovePagePrompt::act_message_for_unit([
        'id' => 'links',
        'title' => 'Merge links',
        'op' => 'batch',
        'paths' => ['2'],
    ]);
    $profile = TurnProfile::from_message($unit, [], ['has_focus' => true]);
    $compose = $profile->compose_allowlist();
    Assert::same(['awpt/propose-block-batch-update'], $compose, 'batch unit offers only the batch propose tool');
}

test_improve_prompt_evaluate_marker_and_act_message();
test_improve_prompt_review_brief_stays_a_simple_request();
test_improve_prompt_parses_units_and_scopes_act_message();
test_improve_prompt_refreshes_stale_evaluate_body();
test_turn_profile_batch_unit_narrows_compose_tools();
test_turn_profile_improve_evaluate_is_read_only();
test_improve_evaluate_work_context_is_design_aware_editing();
test_turn_profile_legacy_improve_still_redesign();
test_turn_profile_improve_act_trusts_plan_isolation();
test_discovery_policy_improve_act_composes_early_when_plan_has_paths();
test_discovery_policy_improve_act_requires_named_preparation();
test_improve_prompt_expand_slash_plan_and_improve();
test_improve_act_none_unit_compose_allowlist_is_empty();
test_failed_evaluate_plan_is_not_executable();
test_improve_plan_structure_rejects_phantom_subsequent_units();
test_improve_plan_structure_rejects_layout_only_brief();
test_improve_tree_snapshot_from_tool_calls();

function test_improve_plan_structure_rejects_phantom_subsequent_units(): void {
    $plan =
        "## Plan\nReplace with documentation layout. After the pattern is placed, subsequent units will "
        . "populate the intro and adjust headings.\n\n```awpt-units\n"
        . '[{"id":"u1","title":"Layout","op":"pattern_replace","paths":["document"],'
        . '"pattern_name":"civicpress/layout-page-documentation",'
        . '"brief":"Replace the page with documentation layout. Subsequent units will populate copy."}]'
        . "\n```";
    $units = ImprovePagePrompt::units_from_plan($plan);
    Assert::same(1, count($units), 'one unit parsed');
    $nits = ImprovePagePrompt::plan_structure_nits($plan, $units, 20);
    Assert::true(count($nits) > 0, 'phantom subsequent units nit');
    Assert::true(
        str_contains(implode(' ', $nits), 'real follow-on') || str_contains(implode(' ', $nits), 'deferred'),
        'nit requires real follow-on work',
    );

    $multi =
        "## Plan\nLayout then batch headings.\n\n```awpt-units\n"
        . '[{"id":"u1","op":"pattern_replace","paths":["document"],"pattern_name":"civicpress/layout-page-documentation",'
        . '"brief":"Map the complete source into the documentation layout"},'
        . '{"id":"u2","op":"batch","paths":["0"],"brief":"Promote H4 to H2 on remaining FAQ paths"}]'
        . "\n```";
    $multi_units = ImprovePagePrompt::units_from_plan($multi);
    Assert::same([], ImprovePagePrompt::plan_structure_nits($multi, $multi_units, 20), 'multi-unit fence is ok');
}

function test_improve_plan_structure_rejects_layout_only_brief(): void {
    $plan =
        "## Plan\nChrome only for now.\n\n```awpt-units\n"
        . '[{"id":"u1","op":"pattern_replace","paths":["document"],"pattern_name":"civicpress/layout-page-documentation",'
        . '"brief":"layout-only (content incomplete) documentation chrome"}]'
        . "\n```";
    $units = ImprovePagePrompt::units_from_plan($plan);
    Assert::true(ImprovePagePrompt::unit_is_layout_only($units[0]), 'layout-only detected');
    Assert::true(
        [] !== ImprovePagePrompt::plan_structure_nits($plan, $units, 20),
        'layout-only is never an executable Improve unit',
    );
}

function test_improve_tree_snapshot_from_tool_calls(): void {
    $snap = ImprovePagePrompt::tree_snapshot_from_tool_calls([
        [
            'tool' => 'awpt/read-block-tree',
            'status' => 'success',
            'output' => [
                'top_level_sections' => [
                    ['path' => '0', 'heading' => 'How do I renew?', 'role' => 'body'],
                    ['path' => '1', 'heading' => 'How do I cancel?', 'role' => 'body'],
                ],
            ],
        ],
    ]);
    Assert::same(2, $snap['top_level_section_count'] ?? null, 'section count');
    Assert::same('0', $snap['sections'][0]['path'] ?? null, 'first path');
    Assert::true(
        ImprovePagePrompt::staged_content_looks_chrome_incomplete(
            '<!-- wp:heading --><h2>Section heading (h2)</h2><!-- /wp:heading -->',
        ),
        'instructional chrome detected',
    );
}

function test_improve_act_none_unit_compose_allowlist_is_empty(): void {
    $unit = [
        'id' => 'unit',
        'title' => 'No change',
        'op' => 'none',
        'paths' => [],
        'brief' => 'Evidence did not yield a concrete pattern or surgical unit.',
    ];
    $msg = ImprovePagePrompt::act_message_for_unit($unit, "## Plan\nnone");
    $profile = TurnProfile::from_message($msg, [], ['has_focus' => true]);
    Assert::true($profile->is_improve_act(), 'act');
    Assert::same('none', ImprovePagePrompt::unit_op_from_act_message($msg), 'op none');
    Assert::same([], $profile->compose_allowlist(), 'op:none must not offer propose tools');
}

function test_failed_evaluate_plan_is_not_executable(): void {
    $runtime = new AWPT\Agent\ProviderRuntime();
    $method = new ReflectionMethod(AWPT\Agent\ProviderRuntime::class, 'failed_evaluate_plan');
    $method->setAccessible(true);
    $plan = $method->invoke($runtime);
    Assert::true(str_contains($plan, '[awpt:plan_failed]'), 'failure carries a non-executable marker');
    Assert::same([], ImprovePagePrompt::units_from_plan($plan), 'failed evaluation cannot synthesize an action');
}
