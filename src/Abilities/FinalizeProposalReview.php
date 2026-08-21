<?php

/**
 * awpt/finalize-proposal-review ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/** Records the agent's explicit decision about an internal Improve candidate. */
final class FinalizeProposalReview implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/finalize-proposal-review',
            'label' => __('Finalize Proposal Review', 'agent-wordpress-terminal'),
            'description' => __(
                'Finishes the current Improve candidate review. Choose accept only after checking the supplied plan, semantic comparison, and rendered evidence; choose abandon when the candidate should not be applied.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'action_id' => ['type' => 'integer', 'minimum' => 1],
                    'decision' => ['type' => 'string', 'enum' => ['accept', 'abandon']],
                    'summary' => [
                        'type' => 'string',
                        'description' => __(
                            'One short evidence-based reason for the decision.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'review_token' => ['type' => 'boolean'],
                ],
                'required' => ['session_id', 'action_id', 'decision', 'summary', 'review_token'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_finalize'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'requires_approval' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_finalize(array $input): bool {
        if (true !== ($input['review_token'] ?? false)) {
            return false;
        }

        $row = new ActionRepository()->get_accessible_row((int) ($input['action_id'] ?? 0));

        return (
            is_array($row)
            && (int) ($row['session_id'] ?? 0) === (int) ($input['session_id'] ?? 0)
            && 'verifying' === (string) ($row['status'] ?? '')
        );
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $actions = new ActionRepository();
        $action_id = (int) ($input['action_id'] ?? 0);
        $action = $actions->format_action($action_id);

        if (null === $action || !$this->can_finalize($input)) {
            return new \WP_Error(
                'awpt_proposal_review_unavailable',
                __('The internal Improve candidate is no longer available for review.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        $decision = sanitize_key((string) ($input['decision'] ?? ''));
        $summary = sanitize_text_field((string) ($input['summary'] ?? ''));

        if ('abandon' === $decision) {
            new StagedPostPreview()->discard_preview_resources(
                is_array($action['payload'] ?? null) ? $action['payload'] : [],
            );
            $actions->update_status($action_id, 'rejected');

            return [
                'accepted' => false,
                'decision' => 'abandon',
                'action_id' => $action_id,
                'summary' => $summary,
            ];
        }

        $actions->update_status($action_id, 'proposed');

        return [
            'accepted' => true,
            'decision' => 'accept',
            'action_id' => $action_id,
            'summary' => $summary,
        ];
    }
}
