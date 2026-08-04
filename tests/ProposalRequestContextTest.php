<?php

/** Tests structured request context enrichment. @package AWPT */

declare(strict_types=1);

use AWPT\Agent\ProposalRequestContext;

function test_proposal_context_adds_identity_and_attachment_evidence_without_overriding_agent_input(): void {
    $context = new ProposalRequestContext();
    $input = $context->enrich(
        10,
        ['post_type' => 'page', 'proposal_key' => 'alternate'],
        [
            'turn_id' => 'turn-123',
            'attachments' => [['id' => 77, 'url' => 'https://example.test/image.png']],
        ],
    );

    Assert::same('page', $input['post_type'] ?? null, 'agent choices should be preserved');
    Assert::same('alternate', $input['proposal_key'] ?? null, 'agent proposal identity should be preserved');
    Assert::same('turn-123', $input['turn_id'] ?? null, 'request identity should be injected');
    Assert::same([77], $input['available_attachment_ids'] ?? null, 'attachments should be structured evidence');
    Assert::same(
        [77],
        $input['required_attachment_ids'] ?? null,
        'composer attachments should be required as inline media evidence',
    );
}

test_proposal_context_adds_identity_and_attachment_evidence_without_overriding_agent_input();

function test_proposal_context_keeps_documents_as_sources_instead_of_inline_images(): void {
    $input = new ProposalRequestContext()->enrich(
        0,
        [],
        [
            'attachments' => [[
                'id' => 22,
                'url' => 'https://example.test/manual.pdf',
                'mime_type' => 'application/pdf',
            ]],
        ],
    );

    Assert::same([22], $input['available_document_ids'] ?? null, 'documents should be exposed as exact sources');
    Assert::same([22], $input['required_document_ids'] ?? null, 'attached documents should remain required evidence');
    Assert::true(
        !array_key_exists('required_attachment_ids', $input),
        'document sources should not be forced into image blocks',
    );
}

test_proposal_context_keeps_documents_as_sources_instead_of_inline_images();

function test_proposal_context_fills_content_update_defaults_from_user_message(): void {
    $input = new ProposalRequestContext()->enrich(
        0,
        ['post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->'],
        [
            'user_message' => 'Not quite. I just want the paragraph breaks in q3 to be restored on page 410.',
        ],
    );

    Assert::same(410, (int) ($input['post_id'] ?? 0), 'page ID from the user message should fill post_id');
    Assert::true('' !== trim((string) ($input['title'] ?? '')), 'action card title should default when omitted');
    Assert::true(
        str_contains((string) ($input['description'] ?? ''), 'paragraph breaks'),
        'description should default from the user request',
    );
    Assert::true(
        str_contains((string) ($input['post_content'] ?? ''), 'Hi'),
        'agent post_content must not be overwritten',
    );
}

test_proposal_context_fills_content_update_defaults_from_user_message();

function test_proposal_context_does_not_override_explicit_content_edit_fields(): void {
    $input = new ProposalRequestContext()->enrich(
        0,
        [
            'post_id' => 99,
            'title' => 'Explicit title',
            'description' => 'Explicit description',
        ],
        [
            'user_message' => 'Fix page 410 paragraph breaks.',
        ],
    );

    Assert::same(99, (int) ($input['post_id'] ?? 0), 'explicit post_id wins over message parsing');
    Assert::same('Explicit title', $input['title'] ?? null, 'explicit title wins');
    Assert::same('Explicit description', $input['description'] ?? null, 'explicit description wins');
}

test_proposal_context_does_not_override_explicit_content_edit_fields();

function test_proposal_context_does_not_bind_focused_post_to_creation_ability(): void {
    awpt_test_reset_state();

    $input = new ProposalRequestContext()->enrich(
        10,
        ['post_type' => 'page', 'post_title' => 'A separate page'],
        ['user_message' => 'Create a new page for filing help.'],
        'awpt/propose-patterned-post',
    );

    Assert::false(array_key_exists('post_id', $input), 'new-post abilities must not inherit session focus');
}

test_proposal_context_does_not_bind_focused_post_to_creation_ability();

function test_proposal_context_rejects_model_invented_composition_minimums(): void {
    $input = new ProposalRequestContext()->enrich(
        0,
        [
            'required_minimum_library_images' => 2,
            'required_minimum_visuals' => 4,
        ],
        ['user_message' => 'Use the Teferi image from the media library.'],
    );

    Assert::false(
        array_key_exists('required_minimum_library_images', $input),
        'a singular named-image request must not become a model-invented multi-image minimum',
    );
    Assert::false(
        array_key_exists('required_minimum_visuals', $input),
        'creative visual density must not become an unstated validation requirement',
    );
}

function test_proposal_context_enforces_exact_user_composition_counts(): void {
    $input = new ProposalRequestContext()->enrich(
        0,
        [
            'required_minimum_library_images' => 1,
            'required_minimum_visuals' => 9,
        ],
        ['user_message' => 'Use four images and three icons.'],
    );

    Assert::same(4, $input['required_minimum_library_images'] ?? 0, 'the user image count should be authoritative');
    Assert::same(3, $input['required_minimum_visuals'] ?? 0, 'the user visual count should be authoritative');
}

test_proposal_context_rejects_model_invented_composition_minimums();
test_proposal_context_enforces_exact_user_composition_counts();

function test_proposal_context_enforces_rendered_page_h1_requirement(): void {
    $input = new ProposalRequestContext()->enrich(
        0,
        ['post_id' => 580],
        ['presentation_requires_h1' => true, 'user_message' => 'Make this page more presentable.'],
        'awpt/propose-block-batch-update',
    );

    Assert::true(
        true === ($input['presentation_requires_h1'] ?? false),
        'the server-derived title requirement must override an omitted provider flag',
    );
}

test_proposal_context_enforces_rendered_page_h1_requirement();
