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
