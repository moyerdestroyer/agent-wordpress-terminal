<?php

/** Agent-owned Improve candidate lifecycle tests. */

declare(strict_types=1);

use AWPT\Abilities\FinalizeProposalReview;
use AWPT\Agent\ProviderRuntime;

final class AwptReviewCandidateDatabase extends wpdb {
    /** @var array<string, mixed> */
    public array $row;

    public function __construct() {
        $this->row = [
            'id' => 71,
            'session_id' => 9,
            'title' => 'Internal candidate',
            'description' => 'Candidate under review',
            'payload_json' => wp_json_encode([
                'operation' => 'content_update',
                'post_id' => 42,
                'post_content' => str_repeat(
                    '<!-- wp:paragraph --><p>Large candidate</p><!-- /wp:paragraph -->',
                    3_000,
                ),
            ]),
            'status' => 'verifying',
            'turn_id' => 'turn-1',
            'proposal_key' => 'candidate',
            'created_at' => '2026-08-21 10:00:00',
            'updated_at' => '2026-08-21 10:00:00',
        ];
    }

    public function get_row(string $query, string $output = ARRAY_A): ?array {
        unset($query, $output);

        return $this->row;
    }

    public function update(
        string $table,
        array $data,
        array $where,
        array|string|null $format = null,
        array|string|null $where_format = null,
    ): int|false {
        unset($table, $where, $format, $where_format);
        $this->row = [...$this->row, ...$data];

        return 1;
    }
}

function test_agent_acceptance_releases_internal_candidate_for_auto_apply(): void {
    $database = new AwptReviewCandidateDatabase();
    $GLOBALS['wpdb'] = $database;
    $result = new FinalizeProposalReview()->execute([
        'session_id' => 9,
        'action_id' => 71,
        'decision' => 'accept',
        'summary' => 'Plan and semantic evidence match.',
        'review_token' => true,
    ]);
    Assert::false(is_wp_error($result), 'agent can accept the active internal candidate');
    Assert::same('proposed', $database->row['status'] ?? null, 'accepted candidate is released to auto-apply');
    Assert::same(true, is_array($result) ? $result['accepted'] ?? null : null, 'acceptance receipt is explicit');
    Assert::same(71, is_array($result) ? $result['action_id'] ?? null : null, 'receipt identifies the durable action');
    Assert::false(
        is_array($result) && array_key_exists('action', $result),
        'large action payload is not repeated in the control-plane receipt',
    );
    Assert::true(
        strlen((string) wp_json_encode($result)) < 2_000,
        'acceptance receipt remains safely below truncation limits',
    );

    $runtime = new ProviderRuntime();
    $decision_method = new ReflectionMethod(ProviderRuntime::class, 'proposal_review_decision');
    $decision = $decision_method->invoke(
        $runtime,
        [[
            'tool' => 'awpt/finalize-proposal-review',
            'status' => 'success',
            'output' => $result,
        ]],
        71,
    );
    Assert::same(71, $decision['action_id'] ?? null, 'runtime recognizes the compact acceptance receipt');

    $action_method = new ReflectionMethod(ProviderRuntime::class, 'accepted_review_action');
    $accepted_action = $action_method->invoke($runtime, $decision);
    Assert::same(
        'proposed',
        $accepted_action['status'] ?? null,
        'runtime reloads the released action for Review Queue',
    );
    Assert::true(
        strlen((string) ($accepted_action['payload']['post_content'] ?? '')) > 100_000,
        'durable action reload retains the full candidate outside the receipt',
    );

    $second = new FinalizeProposalReview()->execute([
        'session_id' => 9,
        'action_id' => 71,
        'decision' => 'accept',
        'summary' => 'Duplicate acceptance.',
        'review_token' => true,
    ]);
    Assert::true(is_wp_error($second), 'acceptance is single-use after the state transition');
    $GLOBALS['wpdb'] = new wpdb();
}

function test_agent_abandonment_rejects_internal_candidate(): void {
    $database = new AwptReviewCandidateDatabase();
    $GLOBALS['wpdb'] = $database;
    $result = new FinalizeProposalReview()->execute([
        'session_id' => 9,
        'action_id' => 71,
        'decision' => 'abandon',
        'summary' => 'Candidate does not satisfy the plan.',
        'review_token' => true,
    ]);
    Assert::false(is_wp_error($result), 'agent can abandon an unsatisfactory candidate');
    Assert::same('rejected', $database->row['status'] ?? null, 'abandoned candidate cannot reach auto-apply');
    $GLOBALS['wpdb'] = new wpdb();
}

test_agent_acceptance_releases_internal_candidate_for_auto_apply();
test_agent_abandonment_rejects_internal_candidate();
