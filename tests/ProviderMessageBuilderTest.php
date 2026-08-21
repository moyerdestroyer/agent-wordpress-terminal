<?php

/**
 * Proportional system prompts by turn profile.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ProviderMessageBuilder;
use AWPT\Agent\ToolCatalogFormatter;
use AWPT\Agent\TurnProfile;
use AWPT\Support\ImprovePagePrompt;

function test_tool_catalog_is_short_and_does_not_list_every_ability(): void {
    $catalog = new ToolCatalogFormatter()->get_system_prompt_catalog();

    Assert::true(strlen($catalog) < 1_200, 'system tool note should stay compact');
    Assert::false(
        str_contains($catalog, 'Auto-callable tools currently enabled'),
        'full ability dump should no longer appear in the system prompt',
    );
    Assert::true(str_contains($catalog, 'function tools'), 'catalog should point at structured tools[] declarations');
}

function test_chat_prompt_omits_compose_and_design_tokens(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $profile = TurnProfile::from_message('Hello there');
    $messages = new ProviderMessageBuilder()->build(
        1,
        [
            'query' => 'Hello there',
            'query_fingerprint' => 'x',
            'items' => [],
            'known_matches' => [],
            'novel_count' => 0,
            'reused_count' => 0,
            'exhausted' => false,
            'skipped' => true,
        ],
        $profile,
    );

    $system = implode("\n", array_map(static fn(array $row): string => 'system' === (string) ($row['role'] ?? '')
        ? (string) ($row['content'] ?? '')
        : '', $messages));

    Assert::true(str_contains($system, 'You are AWPT'), 'core identity should remain');
    Assert::false(
        str_contains($system, 'propose-new-post in the same turn with the complete revised title'),
        'compose revision policy should be omitted on pure chat',
    );
    Assert::false(
        str_contains($system, 'Resolved WordPress design tokens:'),
        'design tokens should be omitted on pure chat',
    );
    Assert::true(strlen($system) < 8_000, 'chat system prompt should be far smaller than the previous always-on wall');
}

function test_compose_prompt_includes_composition_policy(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $message = 'Create a polished landing page for a neighborhood garden club.';
    $profile = TurnProfile::from_message($message);
    $messages = new ProviderMessageBuilder()->build(2, null, $profile);
    $system = implode("\n", array_map(static fn(array $row): string => 'system' === (string) ($row['role'] ?? '')
        ? (string) ($row['content'] ?? '')
        : '', $messages));

    Assert::true(
        str_contains($system, 'awpt/prepare-pattern-draft'),
        'composition turns should include compact pattern preparation policy',
    );
    Assert::true(
        str_contains($system, 'awpt/propose-patterned-post'),
        'composition turns should include pattern composition guidance',
    );
    Assert::true(
        str_contains($system, 'awpt/propose-new-post'),
        'composition turns should retain the unrestricted custom fallback',
    );
    Assert::true($profile->include_design_tokens(), 'composition turns should request design tokens');
    Assert::true(
        str_contains($system, 'injected design-system'),
        'compose policy should treat the compiled design slice as given',
    );
    Assert::true(
        str_contains($system, 'Design system authority'),
        'compose turns should receive the compiled design-system spine',
    );
}

test_tool_catalog_is_short_and_does_not_list_every_ability();
test_chat_prompt_omits_compose_and_design_tokens();
test_compose_prompt_includes_composition_policy();

function test_edit_prompt_prefers_page_content_over_template_writes(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $profile = TurnProfile::from_message('Hey, can you clean up page 410?');
    $messages = new ProviderMessageBuilder()->build(3, null, $profile);
    $system = implode("\n", array_map(static fn(array $row): string => 'system' === (string) ($row['role'] ?? '')
        ? (string) ($row['content'] ?? '')
        : '', $messages));

    Assert::true($profile->needs_edit_module(), 'cleanup should load the edit policy module');
    Assert::true(
        str_contains($system, 'do not propose template or global-styles updates solely to clean up'),
        'edit policy should soft-discourage template writes for page-local cleanup',
    );
    Assert::true(
        str_contains($system, 'do not stop after discovery alone'),
        'edit policy should nudge corrections to stage in the same turn',
    );
}

test_edit_prompt_prefers_page_content_over_template_writes();

function test_presentation_edit_prompt_requires_structural_and_visual_judgment(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $profile = TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]);
    $messages = new ProviderMessageBuilder()->build(4, null, $profile);
    $system = (string) ($messages[0]['content'] ?? '');

    Assert::true(
        str_contains($system, 'theme-enhanced redesign'),
        'redesign prompts should describe theme-enhanced redesign',
    );
    Assert::true(
        str_contains($system, 'awpt/prepare-pattern-change') || str_contains($system, 'awpt/recommend-patterns'),
        'redesign should prefer pattern change prep or recommendation',
    );
    Assert::true(
        str_contains($system, 'replace required authoring placeholders'),
        'redesign must resolve authoring placeholders before staging',
    );
    Assert::true(
        str_contains($system, 'awpt/prepare-pattern-change')
        || str_contains($system, 'awpt/propose-pattern-replace')
        || str_contains($system, 'awpt/recommend-patterns')
        || str_contains($system, 'awpt/read-pattern'),
        'redesign should mention pattern prepare/replace or recommendation paths',
    );
    Assert::true(str_contains($system, 'Do not invent factual claims'), 'redesign still forbids inventing facts');
    Assert::true(
        str_contains($system, 'pattern_unfit_code') || str_contains($system, 'no_recommendations'),
        'redesign prompt should describe honest unfit codes',
    );
    Assert::false(
        str_contains($system, 'every working href'),
        'default redesign must not require full-content preservation checklists',
    );
    Assert::true(
        str_contains($system, 'awpt/propose-content-update') || str_contains($system, 'propose-pattern-insert'),
        'redesign should expose pattern-backed update paths',
    );
    Assert::true(str_contains($system, 'Media Library'), 'redesign module should forbid inventing local media URLs');
    Assert::true(
        str_contains($system, 'Stage exactly one coherent proposal'),
        'redesign guidance must forbid multi-propose batches',
    );
    Assert::false(
        str_contains($system, 'review queue'),
        'redesign guidance should not mention or depend on the hosting surface',
    );
    Assert::true(
        str_contains($system, 'Treat the injected design-system slice as given'),
        'redesign should use the compiled design slice before expanding it',
    );
}

test_presentation_edit_prompt_requires_structural_and_visual_judgment();

function test_improve_evaluate_prompt_uses_injected_design_authority(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();

    $profile = TurnProfile::from_message(ImprovePagePrompt::evaluate_text(), [], ['has_focus' => true]);
    $messages = new ProviderMessageBuilder()->build(5, null, $profile);
    $system = implode("\n", array_map(static fn(array $row): string => 'system' === (string) ($row['role'] ?? '')
        ? (string) ($row['content'] ?? '')
        : '', $messages));

    Assert::true($profile->is_improve_evaluate(), 'evaluate profile');
    Assert::true(str_contains($system, 'pattern_catalog'), 'evaluate module should include the compact pattern index');
    Assert::false(str_contains($system, '"pattern_roles":{'), 'evaluate module should not dump every pattern name');
    Assert::true(
        str_contains($system, 'awpt/recommend-patterns'),
        'evaluate module should direct pattern-section units to recommendation',
    );
    Assert::true(str_contains($system, 'awpt/read-block-tree'), 'evaluate module should name the page-structure read');
    Assert::true(str_contains($system, 'awpt-units'), 'evaluate module asks for the unit list');
    Assert::true(
        str_contains($system, 'pattern_name') && str_contains($system, 'paths'),
        'evaluate fence contract names required pattern unit fields',
    );
    Assert::true(str_contains($system, 'Design system authority'), 'evaluate should receive the compiled design spine');
    Assert::true(str_contains($system, 'prefer_presets'), 'evaluate spine should carry the preset constraint');
    Assert::false(str_contains($system, '"components"'), 'evaluate should not dump the compose component catalog');
}

test_improve_evaluate_prompt_uses_injected_design_authority();

function test_improve_act_prompt_does_not_duplicate_persisted_evaluate_plan(): void {
    $plan =
        "## Evaluation\n\nUnit 2 would remove A: prefixes.\n\n```awpt-units\n"
        . '[{"id":"headings","title":"Fix heading hierarchy","op":"batch","paths":["0.0"],'
        . '"brief":"Set heading level to 2."}]'
        . "\n```";
    $act = ImprovePagePrompt::act_message_for_unit([
        'id' => 'headings',
        'title' => 'Fix heading hierarchy',
        'op' => 'batch',
        'paths' => ['0.0'],
        'changes' => 'Set heading level to 2.',
    ], $plan);
    Assert::true(str_contains($act, 'Fix heading hierarchy'), 'current unit is present');
    Assert::false(str_contains($act, '## Plan'), 'persisted evaluate plan is not resent in the act message');
    Assert::false(str_contains($act, 'remove A: prefixes'), 'evaluate essay remains in durable session history');
}

test_improve_act_prompt_does_not_duplicate_persisted_evaluate_plan();

function test_improve_act_prompt_carries_persisted_pattern_evidence(): void {
    awpt_test_reset_state();
    $GLOBALS['wpdb'] = new wpdb();
    $repository = new AWPT\Database\ImproveWorkflowRepository();
    $repository->begin_evaluate(5, 828, 'eval-evidence');
    $ready = $repository->plan_ready(
        5,
        "## Plan\n\n```awpt-units\n"
        . '[{"id":"toc","title":"Add anchor navigation","op":"pattern_insert","paths":["4"],'
        . '"pattern_name":"civicpress/toc","brief":"Insert sticky TOC after path 4"}]'
        . "\n```",
        [
            ...awpt_test_improve_tree_evidence(828),
            [
                'tool' => 'awpt/recommend-patterns',
                'status' => 'success',
                'output' => [
                    'recommendations' => [
                        [
                            'pattern' => ['name' => 'civicpress/toc', 'title' => 'Table of contents'],
                            'rationale' => 'Anchor nav for long FAQ',
                        ],
                    ],
                ],
            ],
        ],
    );
    Assert::same('plan_ready', $ready['state'] ?? null, 'complete pattern unit stays plan_ready');

    $act = ImprovePagePrompt::act_message_for_unit(
        [
            'id' => 'toc',
            'title' => 'Add anchor navigation',
            'op' => 'pattern_insert',
            'paths' => ['4'],
            'pattern_name' => 'civicpress/toc',
            'brief' => 'Insert sticky TOC after path 4',
        ],
        (string) ($ready['plan'] ?? ''),
    );
    $profile = TurnProfile::from_message($act, [], ['has_focus' => true]);
    $messages = new ProviderMessageBuilder()->build(5, null, $profile);
    $system = implode("\n", array_map(static fn(array $row): string => 'system' === (string) ($row['role'] ?? '')
        ? (string) ($row['content'] ?? '')
        : '', $messages));

    Assert::true($profile->is_improve_act(), 'act profile');
    Assert::true(
        str_contains($system, 'pattern_evidence'),
        'act provenance should carry the persisted pattern evidence key',
    );
    // The wp_json_encode test stub escapes slashes; production does not.
    Assert::true(
        str_contains($system, 'civicpress/toc') || str_contains($system, 'civicpress\/toc'),
        'act provenance should name the ranked pattern from evaluation',
    );
    Assert::true(
        str_contains($system, 'Approved-plan pattern evidence'),
        'act module should direct the model to the injected evidence',
    );
}

test_improve_act_prompt_carries_persisted_pattern_evidence();
