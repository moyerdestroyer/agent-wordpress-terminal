<?php

/**
 * Turn profile classification for proportional prompts and tools.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\TurnProfile;
use AWPT\Support\SiteDesignContext;

function test_turn_profile_chat_stays_light(): void {
    $profile = TurnProfile::from_message('Hello! What can you help with?');

    Assert::same(TurnProfile::TOOL_CHAT, $profile->tool_profile, 'greetings should use the chat tool set');
    Assert::false($profile->auto_retrieve_knowledge, 'pure chat should skip knowledge auto-retrieval');
    Assert::false($profile->include_design_tokens(), 'pure chat should not ship design tokens');
    Assert::false($profile->needs_compose_module(), 'pure chat should omit compose policy');
    Assert::true($profile->history_limit <= 12, 'chat history should stay short');
    Assert::true(count($profile->tool_allowlist()) <= 12, 'chat tool allowlist should stay small');
}

function test_turn_profile_compose_keeps_rich_path(): void {
    $profile = TurnProfile::from_message('Create a landing page for our garden club using theme patterns.');

    Assert::same(TurnProfile::TOOL_COMPOSE, $profile->tool_profile, 'page creation should use compose tools');
    Assert::true($profile->content_turn, 'page creation is a content turn');
    Assert::true($profile->auto_retrieve_knowledge, 'composition should auto-retrieve knowledge');
    Assert::true($profile->needs_compose_module(), 'composition needs compose policy');
    Assert::true($profile->include_design_tokens(), 'composition should include design tokens');
    Assert::true(
        in_array('awpt/propose-new-post', $profile->tool_allowlist(), true),
        'compose allowlist must include propose-new-post',
    );
}

function test_turn_profile_site_fact_uses_investigate(): void {
    $profile = TurnProfile::from_message('What theme is active on this site?');

    Assert::same(TurnProfile::TOOL_INVESTIGATE, $profile->tool_profile, 'theme questions should investigate');
    Assert::true($profile->auto_retrieve_knowledge, 'site-data questions should retrieve knowledge');
    Assert::false($profile->needs_compose_module(), 'theme Q&A should not load compose policy');
    Assert::false(
        in_array('awpt/propose-new-post', $profile->tool_allowlist(), true),
        'investigate should omit the large new-post proposal schema',
    );
}

function test_turn_profile_edit_and_diagnose(): void {
    $edit = TurnProfile::from_message('Hey, can you fix page 408? I would like it to be a documentation page.');
    Assert::same(TurnProfile::TOOL_EDIT, $edit->tool_profile, 'existing page edits should use edit tools');
    Assert::true($edit->content_edit_turn, 'existing page edits are content-edit turns');

    $cleanup = TurnProfile::from_message('Hey, can you clean up page 410?');
    Assert::same(TurnProfile::TOOL_EDIT, $cleanup->tool_profile, 'cleanup phrasing should use edit tools');
    Assert::true($cleanup->content_edit_turn, 'cleanup phrasing is a content-edit turn');
    Assert::false(
        in_array('awpt/propose-template-update', $cleanup->tool_allowlist(), true),
        'page cleanup should not expose template write tools by default',
    );
    Assert::true(
        in_array('awpt/propose-content-update', $cleanup->tool_allowlist(), true),
        'page cleanup should offer content update staging',
    );

    $paragraph_fix = TurnProfile::from_message(
        'I noticed you collapsed paragraph breaks in the answer to q3. Can you fix?',
    );
    Assert::same(
        TurnProfile::TOOL_EDIT,
        $paragraph_fix->tool_profile,
        'paragraph-break fixes should use edit tools without session focus',
    );
    Assert::true($paragraph_fix->content_edit_turn, 'paragraph-break fixes are content-edit turns');

    $heading_promotion = TurnProfile::from_message(
        'Turn this into a scannable FAQ: promote only the question headings currently shown as H4 to H2, preserving all wording, answers, links, and order. Do not add or remove content.',
    );
    Assert::same(
        TurnProfile::TOOL_EDIT,
        $heading_promotion->tool_profile,
        'heading-only preservation requests should use the surgical edit tools',
    );
    Assert::false(
        $heading_promotion->content_turn,
        'a negative add/remove constraint must not trigger the new-page composition path',
    );

    $focused_heading_promotion = TurnProfile::from_message(
        'Change the first question heading only from H4 to H2. Preserve all other content and blocks exactly.',
        [],
        ['has_focus' => true],
    );
    Assert::same(
        TurnProfile::TOOL_EDIT,
        $focused_heading_promotion->tool_profile,
        'focused review edits must remain surgical when preservation wording mentions content',
    );

    $short_fix = TurnProfile::from_message('Can you fix?', [
        'prior_user_messages' => [
            'Hey, can you clean up page 410? IT needs to look better.',
            'Can you fix?',
        ],
    ]);
    Assert::true($short_fix->content_edit_turn, 'short fix after a page cleanup should inherit content-edit');
    Assert::same(TurnProfile::TOOL_EDIT, $short_fix->tool_profile, 'inherited short fix should use edit tools');

    $diag = TurnProfile::from_message('There is a fatal error in the PHP error log after saving.');
    Assert::same(TurnProfile::TOOL_DIAGNOSE, $diag->tool_profile, 'error questions should use diagnose tools');
    Assert::true($diag->needs_diagnosis_module(), 'diagnose turns need diagnosis instructions');
}

function test_presentation_edit_is_inferred_from_prompt_and_focus(): void {
    $profile = TurnProfile::from_message('Make this page more presentable.', [], ['has_focus' => true]);

    Assert::true($profile->presentation_edit, 'presentation intent should be inferred without a surface signal');
    Assert::true($profile->content_edit_turn, 'presentation edits should receive the full edit budget');
    Assert::false($profile->content_turn, 'a focused presentation edit must not enter new-page preparation');
    Assert::same(TurnProfile::TOOL_EDIT, $profile->tool_profile, 'a focused presentation request edits the page');
    Assert::true(
        in_array('awpt/analyze-page', $profile->explore_allowlist(), true),
        'presentation edits should inspect complete page structure',
    );
    Assert::true(
        in_array('awpt/inspect-rendered-element', $profile->explore_allowlist(), true),
        'presentation edits should inspect the rendered current page',
    );
    Assert::true(
        in_array('awpt/propose-block-batch-update', $profile->compose_allowlist(), true),
        'presentation edits should be able to stage coordinated surgical changes atomically',
    );

    $documentation = TurnProfile::from_message('Turn this into a documentation-style page.', [], ['has_focus' => true]);
    Assert::true($documentation->presentation_edit, 'documentation-style requests use the same universal policy');
    Assert::same(TurnProfile::TOOL_EDIT, $documentation->tool_profile, 'documentation styling edits focused content');

    $polish = TurnProfile::from_message('Polish this page.', [], ['has_focus' => true]);
    Assert::true($polish->presentation_edit, 'concise polish requests should receive presentation inspection');

    $generic = TurnProfile::from_message('Improve this page.', [], ['has_focus' => true]);
    Assert::true($generic->presentation_edit, 'the literal generic terminal prompt should use presentation inspection');
    Assert::same(TurnProfile::TOOL_EDIT, $generic->tool_profile, 'generic focused improvements edit the current page');

    $copy = TurnProfile::from_message('Change this page title to Filing Guide.', [], ['has_focus' => true]);
    Assert::false($copy->presentation_edit, 'ordinary copy edits should not require presentation inspection');

    $unfocused = TurnProfile::from_message('Make this page more presentable.');
    Assert::false($unfocused->presentation_edit, 'presentation editing requires an existing focused post');
}

function test_turn_profile_open_proposal_revision_uses_compose(): void {
    $profile = TurnProfile::from_message(
        'Try again and improve the hero section.',
        [
            'has_open_new_post_proposal' => true,
            'prior_user_messages' => ['Create a landing page for WMLS.'],
        ],
        ['has_open_proposals' => true],
    );

    Assert::true($profile->content_turn, 'retry after page create inherits content path');
    Assert::same(TurnProfile::TOOL_COMPOSE, $profile->tool_profile, 'revision of open proposal should compose');
}

function test_turn_profile_design_level_none_for_chat(): void {
    $profile = TurnProfile::from_message('Thanks!');

    Assert::same(SiteDesignContext::LEVEL_NONE, $profile->design_level, 'thanks should be design level none');
    Assert::same(TurnProfile::TOOL_CHAT, $profile->tool_profile, 'thanks should stay on chat tools');
}

function test_turn_profile_explore_compose_allowlists(): void {
    $compose = TurnProfile::from_message('Create a landing page for our garden club.');

    Assert::true($compose->uses_explore_compose_phases(), 'compose turns use explore/compose phases');
    Assert::false(
        in_array('awpt/propose-new-post', $compose->explore_allowlist(), true),
        'explore phase must not offer propose-new-post',
    );
    Assert::true(
        in_array('awpt/list-patterns', $compose->explore_allowlist(), true),
        'explore phase should offer list-patterns',
    );
    Assert::true(
        in_array('awpt/get-work-context', $compose->explore_allowlist(), true),
        'explore phase should expose the explicit work-context contract',
    );
    Assert::true(
        in_array('awpt/read-domain-guidance', $compose->explore_allowlist(), true),
        'explore phase should be able to read referenced theme guidance',
    );
    Assert::same(
        ['awpt/propose-patterned-post', 'awpt/propose-new-post'],
        $compose->compose_allowlist(),
        'new-page composition should retain compact and unrestricted proposal surfaces',
    );
    Assert::same(
        'awpt/propose-patterned-post',
        $compose->compose_primary_ability(),
        'compact patterned proposal should be primary for new pages',
    );

    $edit = TurnProfile::from_message('Hey, can you fix page 408? I would like it to be a documentation page.');
    Assert::true($edit->uses_explore_compose_phases(), 'edit turns use explore/compose phases');
    Assert::true(
        in_array('awpt/propose-content-update', $edit->compose_allowlist(), true),
        'edit compose allowlist includes content update',
    );
    Assert::false(
        in_array('awpt/propose-resource-change', $edit->compose_allowlist(), true),
        'content edits must not expose unrelated resource mutations',
    );
    Assert::false(
        in_array('awpt/propose-content-update', $edit->explore_allowlist(), true),
        'edit explore phase excludes propose tools',
    );

    $icon_edit = TurnProfile::from_message("Adjust the icon on page #32 so that it's a bit bigger.");
    Assert::same(TurnProfile::TOOL_EDIT, $icon_edit->tool_profile, 'targeted visual adjustments should use edit tools');
    Assert::true(
        in_array('awpt/propose-block-attrs-update', $icon_edit->compose_allowlist(), true),
        'targeted edits must retain the surgical block proposal',
    );
}

function test_turn_profile_routes_non_content_mutations_to_one_compatible_operation(): void {
    $template = TurnProfile::from_message('Update the homepage template in the site editor.');
    Assert::same(
        ['awpt/propose-template-update'],
        $template->compose_allowlist(),
        'template requests should route only to template staging',
    );
    Assert::true(
        $template->uses_explore_compose_phases(),
        'recognized non-content mutations should explore before staging',
    );

    $resource = TurnProfile::from_message('Add a link to the primary navigation menu.');
    Assert::same(
        ['awpt/propose-navigation-change'],
        $resource->compose_allowlist(),
        'navigation requests should route only to semantic navigation staging',
    );

    $styles = TurnProfile::from_message('Update the site color palette.');
    Assert::same(
        ['awpt/propose-global-styles-patch', 'awpt/propose-global-styles-update'],
        $styles->compose_allowlist(),
        'global style requests should prefer partial patches while retaining the full-document escape hatch',
    );

    $ambiguous = TurnProfile::from_message('Make the site look better.');
    Assert::same([], $ambiguous->compose_allowlist(), 'ambiguous site changes must not expose a broad proposal menu');
    Assert::true($ambiguous->needs_mutation_clarification(), 'ambiguous site changes should request a narrow target');
}

test_turn_profile_chat_stays_light();
test_turn_profile_compose_keeps_rich_path();
test_turn_profile_site_fact_uses_investigate();
test_turn_profile_edit_and_diagnose();
test_presentation_edit_is_inferred_from_prompt_and_focus();
test_turn_profile_open_proposal_revision_uses_compose();
test_turn_profile_design_level_none_for_chat();
test_turn_profile_explore_compose_allowlists();
test_turn_profile_routes_non_content_mutations_to_one_compatible_operation();
