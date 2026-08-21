<?php

/** Internal Improve candidate review packet tests. */

declare(strict_types=1);

use AWPT\Agent\ProposalPreviewVerifier;

function test_proposal_review_packet_surfaces_semantic_cleanup_misses(): void {
    $before =
        '<!-- wp:heading {"level":4} --><h4>Question?</h4><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A:</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Answer with <a href="/policy">policy 42</a>.</p><!-- /wp:paragraph -->';
    $candidate = $before . '<!-- wp:html --><div>raw</div><!-- /wp:html -->';
    $verification = new ProposalPreviewVerifier()->verify([[
        'tool' => 'awpt/propose-content-update',
        'status' => 'success',
        'output' => [
            'id' => 81,
            'payload' => [
                'original_post_content' => $before,
                'post_content' => $candidate,
            ],
        ],
    ]]);

    Assert::true(is_array($verification), 'candidate without a browser preview still gets semantic review');
    $output = is_array($verification['tool_call']['output'] ?? null) ? $verification['tool_call']['output'] : [];
    $metrics = is_array($output['candidate'] ?? null) ? $output['candidate'] : [];
    Assert::same(1, $metrics['a_prefix_paragraphs'] ?? null, 'standalone A: prefix is visible to review');
    Assert::same(1, $metrics['html_block_count'] ?? null, 'raw HTML blocks are visible to review');
    Assert::same(4, $metrics['headings'][0]['level'] ?? null, 'heading level is visible to review');
    Assert::true(in_array('/policy', $metrics['links'] ?? [], true), 'links are recalled in review evidence');
    Assert::true(in_array('42', $metrics['numbers'] ?? [], true), 'numbers are recalled in review evidence');
    $findings = is_array($metrics['actionable_findings'] ?? null) ? $metrics['actionable_findings'] : [];
    $answer_prefix = array_values(array_filter(
        $findings,
        static fn(mixed $finding): bool => (
            is_array($finding)
            && 'standalone_answer_prefix' === ($finding['kind'] ?? '')
        ),
    ));
    Assert::same('1', $answer_prefix[0]['path'] ?? '', 'semantic misses include an exact candidate path');
    Assert::same(64, strlen((string) ($answer_prefix[0]['fingerprint'] ?? '')), 'finding includes fingerprint');
    Assert::same(
        hash('sha256', $candidate),
        $output['candidate_sha256'] ?? '',
        'review packet identifies the exact staged candidate',
    );
    $message = (string) ($verification['message']['content'] ?? '');
    Assert::true(str_contains($message, 'finalize-proposal-review'), 'review requires an explicit agent decision');
}

test_proposal_review_packet_surfaces_semantic_cleanup_misses();
